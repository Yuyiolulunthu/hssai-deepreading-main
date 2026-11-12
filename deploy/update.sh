#!/usr/bin/env bash
# ============================================================
# 讀伴 ・ 一鍵部署
# 用法:  bash /cluster/workspace/hsstuitor/hssai-deepreading/deploy/update.sh
#
#   1. 從 GitHub 拉取最新版(只接受 fast-forward)
#   2. 若容器設定有變,重建容器(需 docker 權限)
#   3. PHP 語法檢查
#   4. 網站可用性與資料保護檢查
#   任何一項失敗 → 印出回滾指令
#
# 不會動到:config.php(金鑰與密碼)、data/(學生資料、解析結果)
# ============================================================
set -uo pipefail

REPO="${DUPAN_REPO:-/cluster/workspace/hsstuitor/hssai-deepreading}"
URL_LOCAL="${DUPAN_URL_LOCAL:-http://10.0.0.101:3060}"
URL_PUBLIC="${DUPAN_URL_PUBLIC:-https://hssai-deepreading.phys.nthu.edu.tw}"
COMPOSE="${DUPAN_COMPOSE:-docker-compose.yml}"   # 測試站用 docker-compose.test.yml
FAIL=0

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
step()  { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }

cd "$REPO" || { red "找不到專案目錄 $REPO"; exit 1; }

# ---------- 1. 拉取 ----------
step "1/4 從 GitHub 拉取最新版"
BEFORE=$(git rev-parse HEAD 2>/dev/null) || {
    red "git 無法讀取此目錄。若你是第一次在這台機器部署,請先執行:"
    echo "    git config --global --add safe.directory $REPO"
    exit 1
}

DIRTY=$(git status --porcelain --untracked-files=no)
if [ -n "$DIRTY" ]; then
    red "主機上的程式檔有未提交的修改,為避免覆蓋,已停止:"
    echo "$DIRTY"
    echo
    echo "若這些是中文檔名在壓縮/解壓時的亂碼(檔名出現 #U 開頭),可用:"
    echo "    git checkout -- .   # 還原追蹤中的檔案"
    echo "否則請先確認內容(git diff)再決定 git stash 或 git checkout。"
    exit 1
fi

if ! git pull --ff-only; then
    red "git pull 失敗(認證問題,或主機版本與 GitHub 分岔)。網站未受影響。"
    exit 1
fi
AFTER=$(git rev-parse HEAD)

if [ "$BEFORE" = "$AFTER" ]; then
    green "已是最新版,無需更新。"
else
    echo "更新:${BEFORE:0:7} → ${AFTER:0:7}"
    git log --oneline "$BEFORE..$AFTER" | sed 's/^/    /'
fi

# ---------- 2. 容器 ----------
step "2/4 檢查容器設定"
HAS_DOCKER=1
docker ps >/dev/null 2>&1 || HAS_DOCKER=0

NEED_REBUILD=0
if [ "$BEFORE" != "$AFTER" ] && \
   git diff --name-only "$BEFORE" "$AFTER" | grep -qE "^deploy/(Dockerfile|$COMPOSE)$"; then
    NEED_REBUILD=1
fi

if [ "$NEED_REBUILD" = 1 ]; then
    if [ "$HAS_DOCKER" = 1 ]; then
        echo "容器設定有變更,重建中(站會短暫中斷數秒)⋯"
        (cd deploy && docker compose -f "$COMPOSE" up -d --build) || { red "容器重建失敗"; FAIL=1; }
        sleep 5
    else
        red "容器設定有變更,但你沒有 docker 權限,無法重建。"
        echo "請聯絡管理者執行:cd $REPO/deploy && docker compose -f $COMPOSE up -d --build"
        FAIL=1
    fi
else
    echo "無需重建(程式碼為即時掛載,git pull 後立即生效)。"
fi

# ---------- 3. PHP 語法 ----------
step "3/4 PHP 語法檢查"
if [ "$HAS_DOCKER" = 1 ]; then
    BAD=""
    for f in *.php tools/*.php; do
        (cd deploy && docker compose -f "$COMPOSE" exec -T dupan php -l "/var/www/html/$f") >/dev/null 2>&1 || BAD="$BAD $f"
    done
    if [ -n "$BAD" ]; then red "語法錯誤:$BAD"; FAIL=1; else green "全部通過"; fi
else
    echo "(無 docker 權限,略過;以下方網站檢查代替)"
fi

# ---------- 4. 網站與安全 ----------
step "4/4 網站與安全檢查"
check() {   # 說明 網址 期望碼
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$2")
    if echo "$code" | grep -qE "^($3)$"; then
        green "  ✓ $1($code)"
    else
        red   "  ✗ $1:得到 $code,應為 $3"; FAIL=1
    fi
}
check_empty() {   # 說明 網址:必須回 200 且內容為空
    local code size
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$2")
    size=$(curl -s --max-time 20 "$2" | wc -c | tr -d ' ')
    if [ "$code" = "200" ] && [ "$size" = "0" ]; then
        green "  ✓ $1(200,內容為空)"
    else
        red   "  ✗ $1:得到 $code、$size 位元組,應為 200 且內容為空(PHP 可能沒有被解譯,原始碼外洩)"; FAIL=1
    fi
}
check "首頁"           "$URL_LOCAL/"                            "200"
check "站台設定"       "$URL_LOCAL/site.php"                    "200"
check "學生資料受保護" "$URL_LOCAL/data/school.json"            "403|404"
check "統計受保護"     "$URL_LOCAL/data/stats.json"             "403|404"
check "解析受保護"     "$URL_LOCAL/data/analysis/"              "403|404"
check "帳號資料受保護" "$URL_LOCAL/data/accounts.json"          "403|404"
check "學生帳號受保護" "$URL_LOCAL/data/students.json"          "403|404"
check "學生登入金鑰受保護" "$URL_LOCAL/data/.stu_secret"        "403|404"
check "稽核紀錄受保護" "$URL_LOCAL/data/audit.log"              "403|404"
check "試用紀錄受保護" "$URL_LOCAL/data/guest/_index.json"      "403|404"
check "對外首頁"       "$URL_PUBLIC/"                           "200"
check "對外資料受保護" "$URL_PUBLIC/data/school.json"           "403|404"
check "對外帳號資料受保護" "$URL_PUBLIC/data/accounts.json"     "403|404"
check "對外學生帳號受保護" "$URL_PUBLIC/data/students.json"     "403|404"
check "部署設定不外洩" "$URL_PUBLIC/deploy/docker-compose.yml"  "403|404"
check "建帳號工具不外洩" "$URL_PUBLIC/tools/create_account.php" "403|404"
check_empty "設計檔不外洩" "$URL_PUBLIC/prompts_design.php"
check_empty "設定檔不外洩" "$URL_PUBLIC/config.php"

echo
if [ "$FAIL" = 0 ]; then
    green "部署完成,一切正常。"
    exit 0
fi

red "部署後檢查未通過。"
if [ "$BEFORE" != "$AFTER" ]; then
    echo
    echo "若要回到更新前的版本:"
    echo "    cd $REPO && git reset --hard ${BEFORE:0:7}"
    [ "$NEED_REBUILD" = 1 ] && echo "    cd $REPO/deploy && docker compose up -d --build   # 容器設定也要跟著回滾"
    echo "(只回滾程式碼,不影響 config.php 與學生資料)"
fi
exit 1
