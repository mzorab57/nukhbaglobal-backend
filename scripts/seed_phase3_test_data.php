<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';
$database = $config['database'] ?? null;

if (!is_array($database)) {
    fwrite(STDERR, "Database configuration is missing.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    (string) ($database['host'] ?? '127.0.0.1'),
    (int) ($database['port'] ?? 3306),
    (string) ($database['dbname'] ?? ''),
    (string) ($database['charset'] ?? 'utf8mb4')
);

try {
    $pdo = new PDO(
        $dsn,
        (string) ($database['username'] ?? ''),
        (string) ($database['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->beginTransaction();

    $userId = findUserId($pdo);

    if ($userId === null) {
        $userId = createUser($pdo);
    }

    $eventId = findEventId($pdo, $userId);

    if ($eventId === null) {
        $eventId = createEvent($pdo, $userId);
    }

    $ticketId = findTicketId($pdo, $eventId);

    if ($ticketId === null) {
        $ticketId = createTicket($pdo, $eventId);
    }

    $pdo->commit();

    echo json_encode(
        [
            'user_id' => $userId,
            'event_id' => $eventId,
            'ticket_id' => $ticketId,
            'checkout_payload' => [
                'customer_name' => 'Phase 3 Tester',
                'customer_phone' => '07501234567',
                'customer_email' => 'phase3.customer@example.com',
                'customer_address' => 'Erbil',
                'donation_amount' => 0,
                'total_amount' => 50000,
                'items' => [
                    [
                        'ticket_id' => $ticketId,
                        'quantity' => 2,
                    ],
                ],
            ],
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $throwable) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}

function findUserId(PDO $pdo): ?int
{
    $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $statement->execute([
        ':email' => 'phase3.admin@example.com',
    ]);

    $userId = $statement->fetchColumn();

    return $userId === false ? null : (int) $userId;
}

function createUser(PDO $pdo): int
{
    $statement = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, status)
         VALUES (:name, :email, :password, :role, :status)'
    );
    $statement->execute([
        ':name' => 'Phase 3 Admin',
        ':email' => 'phase3.admin@example.com',
        ':password' => password_hash('phase3-test-password', PASSWORD_BCRYPT),
        ':role' => 'admin',
        ':status' => 1,
    ]);

    return (int) $pdo->lastInsertId();
}

function findEventId(PDO $pdo, int $userId): ?int
{
    $statement = $pdo->prepare(
        'SELECT id
         FROM events
         WHERE user_id = :user_id
           AND title = :title
         LIMIT 1'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':title' => json_encode(
            [
                'en' => 'Phase 3 Test Event',
                'ar' => 'Phase 3 Test Event',
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
    ]);

    $eventId = $statement->fetchColumn();

    return $eventId === false ? null : (int) $eventId;
}

function createEvent(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'INSERT INTO events (
            user_id,
            country_id,
            title,
            description,
            desktop_image,
            mobile_image,
            date,
            upcoming,
            status
         ) VALUES (
            :user_id,
            NULL,
            :title,
            :description,
            :desktop_image,
            :mobile_image,
            :date,
            :upcoming,
            :status
         )'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':title' => json_encode(
            [
                'en' => 'Phase 3 Test Event',
                'ar' => 'Phase 3 Test Event',
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        ':description' => json_encode(
            [
                'en' => 'Temporary event used for checkout and ticket issuance verification.',
                'ar' => 'Temporary event used for checkout and ticket issuance verification.',
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        ':desktop_image' => 'phase3-desktop.jpg',
        ':mobile_image' => 'phase3-mobile.jpg',
        ':date' => date('Y-m-d', strtotime('+14 days')),
        ':upcoming' => 1,
        ':status' => 1,
    ]);

    return (int) $pdo->lastInsertId();
}

function findTicketId(PDO $pdo, int $eventId): ?int
{
    $statement = $pdo->prepare(
        'SELECT id
         FROM tickets
         WHERE event_id = :event_id
           AND note = :note
         LIMIT 1'
    );
    $statement->execute([
        ':event_id' => $eventId,
        ':note' => 'phase3-test-ticket',
    ]);

    $ticketId = $statement->fetchColumn();

    return $ticketId === false ? null : (int) $ticketId;
}

function createTicket(PDO $pdo, int $eventId): int
{
    $statement = $pdo->prepare(
        'INSERT INTO tickets (
            event_id,
            sub_event_id,
            title,
            price,
            capacity,
            reserved_count,
            sold_count,
            max_per_user,
            available_from,
            available_until,
            status,
            note
         ) VALUES (
            :event_id,
            NULL,
            :title,
            :price,
            :capacity,
            :reserved_count,
            :sold_count,
            :max_per_user,
            :available_from,
            :available_until,
            :status,
            :note
         )'
    );
    $statement->execute([
        ':event_id' => $eventId,
        ':title' => json_encode(
            [
                'en' => 'Phase 3 Test Ticket',
                'ar' => 'Phase 3 Test Ticket',
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        ':price' => '25000.00',
        ':capacity' => 20,
        ':reserved_count' => 0,
        ':sold_count' => 0,
        ':max_per_user' => 4,
        ':available_from' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ':available_until' => date('Y-m-d H:i:s', strtotime('+10 days')),
        ':status' => 1,
        ':note' => 'phase3-test-ticket',
    ]);

    return (int) $pdo->lastInsertId();
}
