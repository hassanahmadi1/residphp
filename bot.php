<?php
//
error_reporting(0);
//
define("TOKEN","00000"); /// توکن ربات
define("ADMIN_ID",000000); ///عددی ادمین
//
/* ================= DB ================= */
$db = new SQLite3("database.db");

$db->exec("CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY,
    username TEXT,
    phone TEXT,
    step TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS invoices (
    invoice_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    photo_id TEXT,
    status TEXT,
    created_at TEXT,
    admin_message_id INTEGER
)");

$update = json_decode(file_get_contents("php://input"), true);

/* ================= API ================= */
function tg($method, $data){
    $ch = curl_init("https://api.telegram.org/bot".TOKEN."/$method");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

/* ================= Helpers ================= */
function sendMsg($chat_id, $text, $keyboard = null){
    $data = [
        "chat_id" => $chat_id,
        "text" => $text,
        "parse_mode" => "HTML"
    ];
    if ($keyboard) $data["reply_markup"] = json_encode($keyboard);
    tg("sendMessage", $data);
}

function sendPhoto($chat_id, $photo, $caption, $keyboard = null){
    $data = [
        "chat_id" => $chat_id,
        "photo" => $photo,
        "caption" => $caption,
        "parse_mode" => "HTML"
    ];
    if ($keyboard) $data["reply_markup"] = json_encode($keyboard);
    return tg("sendPhoto", $data);
}

function editCaption($chat_id, $message_id, $caption){
    tg("editMessageCaption", [
        "chat_id" => $chat_id,
        "message_id" => $message_id,
        "caption" => $caption,
        "parse_mode" => "HTML",
        "reply_markup" => json_encode(["inline_keyboard" => []])
    ]);
}

function answerCB($id){
    tg("answerCallbackQuery", ["callback_query_id" => $id]);
}

function getUser($uid){
    global $db;
    return $db->querySingle("SELECT * FROM users WHERE user_id=$uid", true);
}

function setUser($uid, $data){
    global $db;
    $db->exec("INSERT OR IGNORE INTO users (user_id) VALUES ($uid)");
    foreach ($data as $k => $v) {
        $db->exec("UPDATE users SET $k='$v' WHERE user_id=$uid");
    }
}

/* ================= USER FLOW ================= */
if (isset($update["message"])) {

    $m = $update["message"];
    $uid = $m["from"]["id"];
    $chat = $m["chat"]["id"];
    $username = $m["from"]["username"] ?? "ندارد";

    $user = getUser($uid);
    $step = $user["step"] ?? "START";

    /* /start */
    if (($m["text"] ?? "") == "/start") {

        // اگر شماره قبلاً ذخیره شده
        if (!empty($user["phone"])) {
            setUser($uid, [
                "username" => $username,
                "step" => "MENU"
            ]);

            sendMsg($chat, "👋 خوش آمدید\nشماره شما قبلاً ثبت شده است.", [
                "keyboard" => [["▶️ شروع"]],
                "resize_keyboard" => true
            ]);
            exit;
        }

        // اگر شماره ثبت نشده
        setUser($uid, [
            "username" => $username,
            "step" => "PHONE"
        ]);

        sendMsg($chat, "📱 لطفاً شماره تلفن خود را تایید کنید", [
            "keyboard" => [
                [
                    ["text" => "📞 تایید شماره", "request_contact" => true]
                ]
            ],
            "resize_keyboard" => true
        ]);
        exit;
    }

    /* PHONE */
    if (isset($m["contact"]) && $step == "PHONE") {

        // امنیت: فقط شماره خود کاربر :) 
        if (($m["contact"]["user_id"] ?? 0) != $uid) {
            sendMsg($chat, "❌ لطفاً شماره <b>خودتان</b> را ارسال کنید");
            exit;
        }

        setUser($uid, [
            "phone" => $m["contact"]["phone_number"],
            "step" => "MENU"
        ]);

        sendMsg($chat, "✅ شماره تایید شد", [
            "keyboard" => [["▶️ شروع"]],
            "resize_keyboard" => true
        ]);
        exit;
    }

    /* MENU */
    if (($m["text"] ?? "") == "▶️ شروع" && $step == "MENU") {
        setUser($uid, ["step" => "WAIT_RECEIPT"]);

        sendMsg(
            $chat,
            "📸 لطفاً <b>فقط عکس رسید پرداخت</b> را ارسال کنید.\n".
            "❌ ارسال هر چیز دیگر پذیرفته نمی‌شود.",
            ["remove_keyboard" => true]
        );
        exit;
    }

    /* RECEIPT */
    if (isset($m["photo"]) && $step == "WAIT_RECEIPT") {

        $photo = end($m["photo"])["file_id"];
        $time = date("Y-m-d H:i:s");

        $db->exec("INSERT INTO invoices (user_id, photo_id, status, created_at)
                   VALUES ($uid, '$photo', 'pending', '$time')");
        $invoice_id = $db->lastInsertRowID();

        // دوباره خواندن کاربر برای اطمینان از شماره :) 
        $user = getUser($uid);

        $caption =
            "🧾 <b>فاکتور #$invoice_id</b>\n".
            "👤 یوزرنیم: @$username\n".
            "🆔 آیدی: <code>$uid</code>\n".
            "📱 شماره: ".$user["phone"]."\n".
            "⏳ وضعیت: در انتظار بررسی";

        $res = sendPhoto(ADMIN_ID, $photo, $caption, [
            "inline_keyboard" => [
                [
                    ["text" => "✅ تایید", "callback_data" => "approve:$invoice_id"],
                    ["text" => "❌ لغو", "callback_data" => "reject:$invoice_id"]
                ]
            ]
        ]);

        $db->exec("UPDATE invoices SET admin_message_id=".$res["result"]["message_id"]."
                   WHERE invoice_id=$invoice_id");

        setUser($uid, ["step" => "PENDING"]);

        sendMsg($chat, "⏳ رسید ثبت شد\nشماره فاکتور: <b>$invoice_id</b>");
        exit;
    }

    /* ERROR: NOT PHOTO */
    if ($step == "WAIT_RECEIPT" && !isset($m["photo"])) {
        sendMsg($chat, "❌ فقط <b>عکس رسید پرداخت</b> مجاز است");
        exit;
    }
}

/* ================= CALLBACKS ================= */
if (isset($update["callback_query"])) {

    $cb = $update["callback_query"];
    answerCB($cb["id"]); // پاسخ به callback (خیلی مهم...)

    if ($cb["from"]["id"] != ADMIN_ID) exit;

    list($action, $id) = explode(":", $cb["data"]);

    $inv = $db->querySingle("SELECT * FROM invoices WHERE invoice_id=$id", true);
    if (!$inv || $inv["status"] != "pending") exit;

    if ($action == "approve") {
        $db->exec("UPDATE invoices SET status='approved' WHERE invoice_id=$id");
        editCaption(ADMIN_ID, $inv["admin_message_id"], "✅ <b>فاکتور #$id تایید شد</b>");
        sendMsg($inv["user_id"], "✅ پرداخت شما تایید شد\nشماره فاکتور: $id");
    }

    if ($action == "reject") {
        $db->exec("UPDATE invoices SET status='rejected' WHERE invoice_id=$id");
        editCaption(ADMIN_ID, $inv["admin_message_id"], "❌ <b>فاکتور #$id لغو شد</b>");
        sendMsg($inv["user_id"], "❌ پرداخت شما لغو شد\nشماره فاکتور: $id");
    }
}
