<?php

$env = parse_ini_file('.env');

foreach ($env as $key => $value) {
    $_ENV[$key] = $value;
}

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$input  = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    exit;
}

$message = $update['message'] ?? null;
if (!$message) {
    exit;
}

$chatId = $message['chat']['id'];
$text   = trim($message['text'] ?? '');

// Функция отправки сообщения
function sendMessage($chatId, $text, $config) {
    $url = 'https://api.telegram.org/bot' . $config['bot_token'] . '/sendMessage';

    $data = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];

    $options = [
        'http' => [
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
        ],
    ];

    $context  = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

// ======= ОБРАБОТКА КОМАНД =======

global $pdo;

if ($text === '/start') {

    $msg = "👋 Привет! Это бот-магазин.\n\n".
           "Команды:\n".
           "/catalog - каталог товаров\n".
           "/cart - корзина\n".
           "/order - оформить заказ\n".
           "/help - помощь\n\n".
           "Чтобы добавить товар в корзину, используй команды:\n".
           "/add1 - iPhone 15\n".
           "/add2 - MacBook Air\n".
           "/add3 - Джинсы Levi's";
    sendMessage($chatId, $msg, $config);

// КАТАЛОГ
} elseif ($text === '/catalog') {

    $stmt = $pdo->query("SELECT id, title, price FROM products LIMIT 10");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$products) {
        sendMessage($chatId, "Каталог пуст.", $config);
    } else {
        $lines = ["📦 <b>Каталог товаров</b>"];
        foreach ($products as $p) {
            $lines[] = "• <b>{$p['title']}</b> — {$p['price']} ₽ (добавить: /add{$p['id']})";
        }
        $textOut = implode("\n", $lines);
        sendMessage($chatId, $textOut, $config);
    }

// ДОБАВЛЕНИЕ В КОРЗИНУ /addX
} elseif (preg_match('/^\/add(\d+)$/', $text, $m)) {

    $productId = (int)$m[1];

    $userId    = $chatId;
    $username  = $message['from']['username'] ?? null;
    $firstName = $message['from']['first_name'] ?? null;

    // сохраняем/обновляем пользователя
    $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, first_name)
                           VALUES (:id, :u, :f)
                           ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name)");
    $stmt->execute([
        ':id' => $userId,
        ':u'  => $username,
        ':f'  => $firstName,
    ]);

    // проверяем товар в корзине
    $stmt = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = :uid AND product_id = :pid");
    $stmt->execute([
        ':uid' => $userId,
        ':pid' => $productId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // увеличиваем количество
        $stmt = $pdo->prepare("UPDATE cart SET quantity = quantity + 1 WHERE id = :id");
        $stmt->execute([':id' => $row['id']]);
    } else {
        // добавляем новую запись
        $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (:uid, :pid, 1)");
        $stmt->execute([
            ':uid' => $userId,
            ':pid' => $productId,
        ]);
    }

    sendMessage($chatId, "✅ Товар добавлен в корзину.", $config);

// ПРОСМОТР КОРЗИНЫ
} elseif ($text === '/cart') {

    $userId = $chatId;

    $sql = "SELECT p.title, p.price, c.quantity
            FROM cart c
            JOIN products p ON p.id = c.product_id
            WHERE c.user_id = :uid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        sendMessage($chatId, "🛒 Ваша корзина пуста.", $config);
    } else {
        $lines = ["🛒 <b>Ваша корзина</b>"];
        $total = 0;
        foreach ($items as $it) {
            $sum = $it['price'] * $it['quantity'];
            $total += $sum;
            $lines[] = "• {$it['title']} — {$it['quantity']} шт × {$it['price']} ₽ = {$sum} ₽";
        }
        $lines[] = "\nИтого: <b>{$total} ₽</b>";
        sendMessage($chatId, implode("\n", $lines), $config);
    }

// ОФОРМЛЕНИЕ ЗАКАЗА
} elseif ($text === '/order') {

    $userId = $chatId;

    $sql = "SELECT p.id, p.title, p.price, c.quantity
            FROM cart c
            JOIN products p ON p.id = c.product_id
            WHERE c.user_id = :uid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        sendMessage($chatId, "🛒 Нельзя оформить заказ: корзина пуста.", $config);
    } else {
        $total = 0;
        foreach ($items as $it) {
            $total += $it['price'] * $it['quantity'];
        }

        $stmt = $pdo->prepare("INSERT INTO orders (user_id, total, status, payload)
                               VALUES (:uid, :total, 'new', 'telegram_order')");
        $stmt->execute([
            ':uid'   => $userId,
            ':total' => $total,
        ]);
        $orderId = $pdo->lastInsertId();

        $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price)
                                   VALUES (:oid, :pid, :qty, :price)");
        foreach ($items as $it) {
            $stmtItem->execute([
                ':oid'   => $orderId,
                ':pid'   => $it['id'],
                ':qty'   => $it['quantity'],
                ':price' => $it['price'],
            ]);
        }

        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);

        $textOut = "✅ Заказ №{$orderId} оформлен.\n".
                   "Сумма: <b>{$total} ₽</b>.\n".
                   "Статус: new (ожидает подтверждения).";
        sendMessage($chatId, $textOut, $config);
    }

// HELP И ПРОЧЕЕ
} elseif ($text === '/help') {

    sendMessage($chatId, "Команды: /start, /catalog, /cart, /order", $config);

} else {

    sendMessage($chatId, "Не понимаю команду. Напиши /start.", $config);
}
