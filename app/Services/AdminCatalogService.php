<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use PDO;
use RuntimeException;

final class AdminCatalogService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getEvents(PDO $pdo, array $filters): array
    {
        $whereClauses = ['e.deleted_at IS NULL'];
        $parameters = [];

        $status = $this->nullableFlag($filters['status'] ?? null);
        if ($status !== null) {
            $whereClauses[] = 'e.status = :status';
            $parameters[':status'] = $status;
        }

        $upcoming = $this->nullableFlag($filters['upcoming'] ?? null);
        if ($upcoming !== null) {
            $whereClauses[] = 'e.upcoming = :upcoming';
            $parameters[':upcoming'] = $upcoming;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . $query . '%';
            $whereClauses[] = '(
                JSON_UNQUOTE(JSON_EXTRACT(e.title, "$.en")) LIKE :query_title_en
                OR JSON_UNQUOTE(JSON_EXTRACT(e.title, "$.ar")) LIKE :query_title_ar
                OR DATE_FORMAT(e.date, "%Y-%m-%d") LIKE :query_date
            )';
            $parameters[':query_title_en'] = $queryValue;
            $parameters[':query_title_ar'] = $queryValue;
            $parameters[':query_date'] = $queryValue;
        }

        $whereSql = implode(' AND ', $whereClauses);

        $statement = $pdo->prepare(
            'SELECT
                e.id,
                e.user_id,
                e.country_id,
                e.title,
                e.description,
                e.desktop_image,
                e.mobile_image,
                e.date,
                e.upcoming,
                e.status,
                e.created_at,
                e.updated_at,
                c.name AS country_name,
                COUNT(DISTINCT t.id) AS tickets_count,
                COALESCE(SUM(t.capacity), 0) AS total_capacity,
                COALESCE(SUM(t.sold_count), 0) AS sold_count
             FROM events e
             LEFT JOIN countries c ON c.id = e.country_id
             LEFT JOIN tickets t ON t.event_id = e.id AND t.deleted_at IS NULL
             WHERE ' . $whereSql . '
             GROUP BY
                e.id,
                e.user_id,
                e.country_id,
                e.title,
                e.description,
                e.desktop_image,
                e.mobile_image,
                e.date,
                e.upcoming,
                e.status,
                e.created_at,
                e.updated_at,
                c.name
             ORDER BY e.date DESC, e.id DESC'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_INT);
            if (!is_int($value)) {
                $statement->bindValue($key, $value);
            }
        }
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map([$this, 'mapEventRow'], $rows),
            'filters' => [
                'status' => $status,
                'upcoming' => $upcoming,
                'query' => $query !== '' ? $query : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEventDetails(PDO $pdo, int $eventId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                e.id,
                e.user_id,
                e.country_id,
                e.title,
                e.description,
                e.desktop_image,
                e.mobile_image,
                e.date,
                e.upcoming,
                e.status,
                e.created_at,
                e.updated_at,
                c.name AS country_name
             FROM events e
             LEFT JOIN countries c ON c.id = e.country_id
             WHERE e.id = :event_id
               AND e.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            ':event_id' => $eventId,
        ]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'event' => $this->mapEventRow($row),
            'tickets' => $this->getTicketsByEvent($pdo, $eventId),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createEvent(PDO $pdo, array $payload, int $userId): array
    {
        $normalized = $this->validateEventPayload($pdo, $payload, true);

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
                :country_id,
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
            ':country_id' => $normalized['country_id'],
            ':title' => $this->encodeJson($normalized['title']),
            ':description' => $this->encodeJson($normalized['description']),
            ':desktop_image' => $normalized['desktop_image'],
            ':mobile_image' => $normalized['mobile_image'],
            ':date' => $normalized['date'],
            ':upcoming' => $normalized['upcoming'],
            ':status' => $normalized['status'],
        ]);

        $eventId = (int) $pdo->lastInsertId();
        $event = $this->getEventDetails($pdo, $eventId);

        if ($event === null) {
            throw new RuntimeException('Created event could not be loaded.');
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateEvent(PDO $pdo, int $eventId, array $payload): ?array
    {
        $existing = $this->getEventRecord($pdo, $eventId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validateEventPayload($pdo, $payload, false, $existing);

        $statement = $pdo->prepare(
            'UPDATE events
             SET country_id = :country_id,
                 title = :title,
                 description = :description,
                 desktop_image = :desktop_image,
                 mobile_image = :mobile_image,
                 date = :date,
                 upcoming = :upcoming,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :event_id'
        );
        $statement->execute([
            ':country_id' => $normalized['country_id'],
            ':title' => $this->encodeJson($normalized['title']),
            ':description' => $this->encodeJson($normalized['description']),
            ':desktop_image' => $normalized['desktop_image'],
            ':mobile_image' => $normalized['mobile_image'],
            ':date' => $normalized['date'],
            ':upcoming' => $normalized['upcoming'],
            ':status' => $normalized['status'],
            ':event_id' => $eventId,
        ]);

        return $this->getEventDetails($pdo, $eventId);
    }

    public function deleteEvent(PDO $pdo, int $eventId): bool
    {
        $event = $this->getEventRecord($pdo, $eventId, true);
        if ($event === null) {
            return false;
        }

        $usageStatement = $pdo->prepare(
            'SELECT
                COUNT(*) AS tickets_count,
                COALESCE(SUM(reserved_count), 0) AS reserved_count,
                COALESCE(SUM(sold_count), 0) AS sold_count
             FROM tickets
             WHERE event_id = :event_id
               AND deleted_at IS NULL'
        );
        $usageStatement->execute([
            ':event_id' => $eventId,
        ]);
        $usage = $usageStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        if ((int) ($usage['reserved_count'] ?? 0) > 0 || (int) ($usage['sold_count'] ?? 0) > 0) {
            throw new RuntimeException('Events with reserved or sold tickets cannot be deleted.');
        }

        $deleteTicketsStatement = $pdo->prepare(
            'UPDATE tickets
             SET status = 0,
                 deleted_at = NOW(),
                 updated_at = NOW()
             WHERE event_id = :event_id
               AND deleted_at IS NULL'
        );
        $deleteTicketsStatement->execute([
            ':event_id' => $eventId,
        ]);

        $deleteEventStatement = $pdo->prepare(
            'UPDATE events
             SET status = 0,
                 deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :event_id'
        );
        $deleteEventStatement->execute([
            ':event_id' => $eventId,
        ]);

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTicketsByEvent(PDO $pdo, int $eventId): array
    {
        $statement = $pdo->prepare(
            'SELECT
                t.id,
                t.event_id,
                t.sub_event_id,
                t.title,
                t.price,
                t.capacity,
                t.reserved_count,
                t.sold_count,
                t.max_per_user,
                t.available_from,
                t.available_until,
                t.status,
                t.note,
                t.created_at,
                t.updated_at,
                se.title AS sub_event_title,
                e.title AS event_title,
                e.date AS event_date
             FROM tickets t
             INNER JOIN events e ON e.id = t.event_id
             LEFT JOIN sub_events se ON se.id = t.sub_event_id
             WHERE t.event_id = :event_id
               AND t.deleted_at IS NULL
             ORDER BY t.id DESC'
        );
        $statement->execute([
            ':event_id' => $eventId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'mapTicketRow'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTicketDetails(PDO $pdo, int $ticketId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                t.id,
                t.event_id,
                t.sub_event_id,
                t.title,
                t.price,
                t.capacity,
                t.reserved_count,
                t.sold_count,
                t.max_per_user,
                t.available_from,
                t.available_until,
                t.status,
                t.note,
                t.created_at,
                t.updated_at,
                se.title AS sub_event_title,
                e.title AS event_title,
                e.date AS event_date
             FROM tickets t
             INNER JOIN events e ON e.id = t.event_id
             LEFT JOIN sub_events se ON se.id = t.sub_event_id
             WHERE t.id = :ticket_id
               AND t.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            ':ticket_id' => $ticketId,
        ]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapTicketRow($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTicket(PDO $pdo, int $eventId, array $payload): array
    {
        $event = $this->getEventRecord($pdo, $eventId);
        if ($event === null) {
            throw new RuntimeException('Event was not found.');
        }

        $normalized = $this->validateTicketPayload($pdo, $eventId, $payload, true);
        $statement = $pdo->prepare(
            'INSERT INTO tickets (
                event_id,
                sub_event_id,
                title,
                price,
                capacity,
                max_per_user,
                available_from,
                available_until,
                status,
                note
             ) VALUES (
                :event_id,
                :sub_event_id,
                :title,
                :price,
                :capacity,
                :max_per_user,
                :available_from,
                :available_until,
                :status,
                :note
             )'
        );
        $statement->execute([
            ':event_id' => $eventId,
            ':sub_event_id' => $normalized['sub_event_id'],
            ':title' => $this->encodeJson($normalized['title']),
            ':price' => number_format($normalized['price'], 2, '.', ''),
            ':capacity' => $normalized['capacity'],
            ':max_per_user' => $normalized['max_per_user'],
            ':available_from' => $normalized['available_from'],
            ':available_until' => $normalized['available_until'],
            ':status' => $normalized['status'],
            ':note' => $normalized['note'],
        ]);

        $ticketId = (int) $pdo->lastInsertId();
        $ticket = $this->getTicketDetails($pdo, $ticketId);

        if ($ticket === null) {
            throw new RuntimeException('Created ticket could not be loaded.');
        }

        return $ticket;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateTicket(PDO $pdo, int $ticketId, array $payload): ?array
    {
        $existing = $this->getTicketRecord($pdo, $ticketId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validateTicketPayload($pdo, (int) $existing['event_id'], $payload, false, $existing);
        $statement = $pdo->prepare(
            'UPDATE tickets
             SET sub_event_id = :sub_event_id,
                 title = :title,
                 price = :price,
                 capacity = :capacity,
                 max_per_user = :max_per_user,
                 available_from = :available_from,
                 available_until = :available_until,
                 status = :status,
                 note = :note,
                 updated_at = NOW()
             WHERE id = :ticket_id'
        );
        $statement->execute([
            ':sub_event_id' => $normalized['sub_event_id'],
            ':title' => $this->encodeJson($normalized['title']),
            ':price' => number_format($normalized['price'], 2, '.', ''),
            ':capacity' => $normalized['capacity'],
            ':max_per_user' => $normalized['max_per_user'],
            ':available_from' => $normalized['available_from'],
            ':available_until' => $normalized['available_until'],
            ':status' => $normalized['status'],
            ':note' => $normalized['note'],
            ':ticket_id' => $ticketId,
        ]);

        return $this->getTicketDetails($pdo, $ticketId);
    }

    public function deleteTicket(PDO $pdo, int $ticketId): bool
    {
        $ticket = $this->getTicketRecord($pdo, $ticketId, true);
        if ($ticket === null) {
            return false;
        }

        if ((int) ($ticket['reserved_count'] ?? 0) > 0 || (int) ($ticket['sold_count'] ?? 0) > 0) {
            throw new RuntimeException('Tickets with reserved or sold quantities cannot be deleted.');
        }

        $statement = $pdo->prepare(
            'UPDATE tickets
             SET status = 0,
                 deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :ticket_id'
        );
        $statement->execute([
            ':ticket_id' => $ticketId,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getCountries(PDO $pdo, array $filters): array
    {
        $whereClauses = ['c.deleted_at IS NULL'];
        $parameters = [];

        $status = $this->nullableFlag($filters['status'] ?? null);
        if ($status !== null) {
            $whereClauses[] = 'c.status = :status';
            $parameters[':status'] = $status;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . $query . '%';
            $whereClauses[] = '(
                JSON_UNQUOTE(JSON_EXTRACT(c.name, "$.en")) LIKE :query_name_en
                OR JSON_UNQUOTE(JSON_EXTRACT(c.name, "$.ar")) LIKE :query_name_ar
            )';
            $parameters[':query_name_en'] = $queryValue;
            $parameters[':query_name_ar'] = $queryValue;
        }

        $statement = $pdo->prepare(
            'SELECT
                c.id,
                c.user_id,
                c.name,
                c.status,
                c.created_at,
                c.updated_at,
                COUNT(DISTINCT ci.id) AS cities_count,
                COUNT(DISTINCT e.id) AS events_count
             FROM countries c
             LEFT JOIN cities ci ON ci.country_id = c.id AND ci.deleted_at IS NULL
             LEFT JOIN events e ON e.country_id = c.id AND e.deleted_at IS NULL
             WHERE ' . implode(' AND ', $whereClauses) . '
             GROUP BY c.id, c.user_id, c.name, c.status, c.created_at, c.updated_at
             ORDER BY c.id DESC'
        );
        foreach ($parameters as $key => $value) {
            if (is_int($value)) {
                $statement->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($key, $value);
        }
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map([$this, 'mapCountryRow'], $rows),
            'filters' => [
                'status' => $status,
                'query' => $query !== '' ? $query : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCountryDetails(PDO $pdo, int $countryId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, user_id, name, status, created_at, updated_at
             FROM countries
             WHERE id = :country_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            ':country_id' => $countryId,
        ]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'country' => $this->mapCountryRow($row),
            'cities' => $this->getCities($pdo, ['country_id' => $countryId])['items'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCountry(PDO $pdo, array $payload, int $userId): array
    {
        $normalized = $this->validateCountryPayload($payload, true);
        $statement = $pdo->prepare(
            'INSERT INTO countries (user_id, name, status)
             VALUES (:user_id, :name, :status)'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':name' => $this->encodeJson($normalized['name']),
            ':status' => $normalized['status'],
        ]);

        $country = $this->getCountryDetails($pdo, (int) $pdo->lastInsertId());
        if ($country === null) {
            throw new RuntimeException('Created country could not be loaded.');
        }

        return $country;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateCountry(PDO $pdo, int $countryId, array $payload): ?array
    {
        $existing = $this->getCountryRecord($pdo, $countryId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validateCountryPayload($payload, false, $existing);
        $statement = $pdo->prepare(
            'UPDATE countries
             SET name = :name,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :country_id'
        );
        $statement->execute([
            ':name' => $this->encodeJson($normalized['name']),
            ':status' => $normalized['status'],
            ':country_id' => $countryId,
        ]);

        return $this->getCountryDetails($pdo, $countryId);
    }

    public function deleteCountry(PDO $pdo, int $countryId): bool
    {
        $country = $this->getCountryRecord($pdo, $countryId, true);
        if ($country === null) {
            return false;
        }

        $citiesCountStatement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM cities
             WHERE country_id = :country_id
               AND deleted_at IS NULL'
        );
        $citiesCountStatement->execute([
            ':country_id' => $countryId,
        ]);
        if ((int) $citiesCountStatement->fetchColumn() > 0) {
            throw new RuntimeException('Countries with active cities cannot be deleted.');
        }

        $nullEventsStatement = $pdo->prepare(
            'UPDATE events
             SET country_id = NULL,
                 updated_at = NOW()
             WHERE country_id = :country_id
               AND deleted_at IS NULL'
        );
        $nullEventsStatement->execute([
            ':country_id' => $countryId,
        ]);

        $statement = $pdo->prepare(
            'UPDATE countries
             SET status = 0,
                 deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :country_id'
        );
        $statement->execute([
            ':country_id' => $countryId,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getCities(PDO $pdo, array $filters): array
    {
        $whereClauses = ['ci.deleted_at IS NULL'];
        $parameters = [];

        $status = $this->nullableFlag($filters['status'] ?? null);
        if ($status !== null) {
            $whereClauses[] = 'ci.status = :status';
            $parameters[':status'] = $status;
        }

        $countryId = $this->normalizeNullablePositiveInt($filters['country_id'] ?? null);
        if ($countryId !== null) {
            $whereClauses[] = 'ci.country_id = :country_id';
            $parameters[':country_id'] = $countryId;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . $query . '%';
            $whereClauses[] = '(
                JSON_UNQUOTE(JSON_EXTRACT(ci.name, "$.en")) LIKE :query_name_en
                OR JSON_UNQUOTE(JSON_EXTRACT(ci.name, "$.ar")) LIKE :query_name_ar
                OR JSON_UNQUOTE(JSON_EXTRACT(c.name, "$.en")) LIKE :query_country_en
                OR JSON_UNQUOTE(JSON_EXTRACT(c.name, "$.ar")) LIKE :query_country_ar
            )';
            $parameters[':query_name_en'] = $queryValue;
            $parameters[':query_name_ar'] = $queryValue;
            $parameters[':query_country_en'] = $queryValue;
            $parameters[':query_country_ar'] = $queryValue;
        }

        $statement = $pdo->prepare(
            'SELECT
                ci.id,
                ci.country_id,
                ci.user_id,
                ci.name,
                ci.status,
                ci.created_at,
                ci.updated_at,
                c.name AS country_name,
                COUNT(DISTINCT se.id) AS sub_events_count
             FROM cities ci
             INNER JOIN countries c ON c.id = ci.country_id
             LEFT JOIN sub_events se ON se.city_id = ci.id AND se.deleted_at IS NULL
             WHERE ' . implode(' AND ', $whereClauses) . '
             GROUP BY ci.id, ci.country_id, ci.user_id, ci.name, ci.status, ci.created_at, ci.updated_at, c.name
             ORDER BY ci.id DESC'
        );
        foreach ($parameters as $key => $value) {
            if (is_int($value)) {
                $statement->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($key, $value);
        }
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map([$this, 'mapCityRow'], $rows),
            'filters' => [
                'status' => $status,
                'countryId' => $countryId,
                'query' => $query !== '' ? $query : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCityDetails(PDO $pdo, int $cityId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                ci.id,
                ci.country_id,
                ci.user_id,
                ci.name,
                ci.status,
                ci.created_at,
                ci.updated_at,
                c.name AS country_name
             FROM cities ci
             INNER JOIN countries c ON c.id = ci.country_id
             WHERE ci.id = :city_id
               AND ci.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            ':city_id' => $cityId,
        ]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'city' => $this->mapCityRow($row),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCity(PDO $pdo, array $payload, int $userId): array
    {
        $normalized = $this->validateCityPayload($pdo, $payload, true);
        $statement = $pdo->prepare(
            'INSERT INTO cities (country_id, user_id, name, status)
             VALUES (:country_id, :user_id, :name, :status)'
        );
        $statement->execute([
            ':country_id' => $normalized['country_id'],
            ':user_id' => $userId,
            ':name' => $this->encodeJson($normalized['name']),
            ':status' => $normalized['status'],
        ]);

        $city = $this->getCityDetails($pdo, (int) $pdo->lastInsertId());
        if ($city === null) {
            throw new RuntimeException('Created city could not be loaded.');
        }

        return $city;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateCity(PDO $pdo, int $cityId, array $payload): ?array
    {
        $existing = $this->getCityRecord($pdo, $cityId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validateCityPayload($pdo, $payload, false, $existing);
        $statement = $pdo->prepare(
            'UPDATE cities
             SET country_id = :country_id,
                 name = :name,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :city_id'
        );
        $statement->execute([
            ':country_id' => $normalized['country_id'],
            ':name' => $this->encodeJson($normalized['name']),
            ':status' => $normalized['status'],
            ':city_id' => $cityId,
        ]);

        return $this->getCityDetails($pdo, $cityId);
    }

    public function deleteCity(PDO $pdo, int $cityId): bool
    {
        $city = $this->getCityRecord($pdo, $cityId, true);
        if ($city === null) {
            return false;
        }

        $subEventsCountStatement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM sub_events
             WHERE city_id = :city_id
               AND deleted_at IS NULL'
        );
        $subEventsCountStatement->execute([
            ':city_id' => $cityId,
        ]);

        if ((int) $subEventsCountStatement->fetchColumn() > 0) {
            throw new RuntimeException('Cities with active sub events cannot be deleted.');
        }

        $statement = $pdo->prepare(
            'UPDATE cities
             SET status = 0,
                 deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :city_id'
        );
        $statement->execute([
            ':city_id' => $cityId,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getSubEvents(PDO $pdo, array $filters): array
    {
        $whereClauses = ['se.deleted_at IS NULL'];
        $parameters = [];

        $eventId = $this->normalizeNullablePositiveInt($filters['event_id'] ?? null);
        if ($eventId !== null) {
            $whereClauses[] = 'se.event_id = :event_id';
            $parameters[':event_id'] = $eventId;
        }

        $cityId = $this->normalizeNullablePositiveInt($filters['city_id'] ?? null);
        if ($cityId !== null) {
            $whereClauses[] = 'se.city_id = :city_id';
            $parameters[':city_id'] = $cityId;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . $query . '%';
            $whereClauses[] = '(
                JSON_UNQUOTE(JSON_EXTRACT(se.title, "$.en")) LIKE :query_title_en
                OR JSON_UNQUOTE(JSON_EXTRACT(se.title, "$.ar")) LIKE :query_title_ar
                OR JSON_UNQUOTE(JSON_EXTRACT(ci.name, "$.en")) LIKE :query_city_en
                OR JSON_UNQUOTE(JSON_EXTRACT(ci.name, "$.ar")) LIKE :query_city_ar
            )';
            $parameters[':query_title_en'] = $queryValue;
            $parameters[':query_title_ar'] = $queryValue;
            $parameters[':query_city_en'] = $queryValue;
            $parameters[':query_city_ar'] = $queryValue;
        }

        $statement = $pdo->prepare(
            'SELECT
                se.id,
                se.event_id,
                se.city_id,
                se.title,
                se.sub_title,
                se.description,
                se.location,
                se.date,
                se.start_time,
                se.end_time,
                se.created_at,
                se.updated_at,
                ci.name AS city_name,
                e.title AS event_title,
                COUNT(DISTINCT t.id) AS tickets_count
             FROM sub_events se
             INNER JOIN cities ci ON ci.id = se.city_id
             INNER JOIN events e ON e.id = se.event_id
             LEFT JOIN tickets t ON t.sub_event_id = se.id AND t.deleted_at IS NULL
             WHERE ' . implode(' AND ', $whereClauses) . '
             GROUP BY
                se.id, se.event_id, se.city_id, se.title, se.sub_title, se.description,
                se.location, se.date, se.start_time, se.end_time, se.created_at, se.updated_at,
                ci.name, e.title
             ORDER BY se.date DESC, se.id DESC'
        );
        foreach ($parameters as $key => $value) {
            if (is_int($value)) {
                $statement->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($key, $value);
        }
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map([$this, 'mapSubEventRow'], $rows),
            'filters' => [
                'eventId' => $eventId,
                'cityId' => $cityId,
                'query' => $query !== '' ? $query : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSubEventDetails(PDO $pdo, int $subEventId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                se.id,
                se.event_id,
                se.city_id,
                se.title,
                se.sub_title,
                se.description,
                se.location,
                se.date,
                se.start_time,
                se.end_time,
                se.created_at,
                se.updated_at,
                ci.name AS city_name,
                e.title AS event_title
             FROM sub_events se
             INNER JOIN cities ci ON ci.id = se.city_id
             INNER JOIN events e ON e.id = se.event_id
             WHERE se.id = :sub_event_id
               AND se.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            ':sub_event_id' => $subEventId,
        ]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'subEvent' => $this->mapSubEventRow($row),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSubEvent(PDO $pdo, int $eventId, array $payload): array
    {
        if ($this->getEventRecord($pdo, $eventId) === null) {
            throw new RuntimeException('Event was not found.');
        }

        $normalized = $this->validateSubEventPayload($pdo, $eventId, $payload, true);
        $statement = $pdo->prepare(
            'INSERT INTO sub_events (
                event_id,
                city_id,
                title,
                sub_title,
                description,
                location,
                date,
                start_time,
                end_time
             ) VALUES (
                :event_id,
                :city_id,
                :title,
                :sub_title,
                :description,
                :location,
                :date,
                :start_time,
                :end_time
             )'
        );
        $statement->execute([
            ':event_id' => $eventId,
            ':city_id' => $normalized['city_id'],
            ':title' => $this->encodeJson($normalized['title']),
            ':sub_title' => $normalized['sub_title'] !== null ? $this->encodeJson($normalized['sub_title']) : null,
            ':description' => $this->encodeJson($normalized['description']),
            ':location' => $normalized['location'] !== null ? $this->encodeJson($normalized['location']) : null,
            ':date' => $normalized['date'],
            ':start_time' => $normalized['start_time'],
            ':end_time' => $normalized['end_time'],
        ]);

        $subEvent = $this->getSubEventDetails($pdo, (int) $pdo->lastInsertId());
        if ($subEvent === null) {
            throw new RuntimeException('Created sub event could not be loaded.');
        }

        return $subEvent;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateSubEvent(PDO $pdo, int $subEventId, array $payload): ?array
    {
        $existing = $this->getSubEventRecord($pdo, $subEventId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validateSubEventPayload($pdo, (int) $existing['event_id'], $payload, false, $existing);
        $statement = $pdo->prepare(
            'UPDATE sub_events
             SET city_id = :city_id,
                 title = :title,
                 sub_title = :sub_title,
                 description = :description,
                 location = :location,
                 date = :date,
                 start_time = :start_time,
                 end_time = :end_time,
                 updated_at = NOW()
             WHERE id = :sub_event_id'
        );
        $statement->execute([
            ':city_id' => $normalized['city_id'],
            ':title' => $this->encodeJson($normalized['title']),
            ':sub_title' => $normalized['sub_title'] !== null ? $this->encodeJson($normalized['sub_title']) : null,
            ':description' => $this->encodeJson($normalized['description']),
            ':location' => $normalized['location'] !== null ? $this->encodeJson($normalized['location']) : null,
            ':date' => $normalized['date'],
            ':start_time' => $normalized['start_time'],
            ':end_time' => $normalized['end_time'],
            ':sub_event_id' => $subEventId,
        ]);

        return $this->getSubEventDetails($pdo, $subEventId);
    }

    public function deleteSubEvent(PDO $pdo, int $subEventId): bool
    {
        $subEvent = $this->getSubEventRecord($pdo, $subEventId, true);
        if ($subEvent === null) {
            return false;
        }

        $ticketsUsageStatement = $pdo->prepare(
            'SELECT
                COALESCE(SUM(reserved_count), 0) AS reserved_count,
                COALESCE(SUM(sold_count), 0) AS sold_count
             FROM tickets
             WHERE sub_event_id = :sub_event_id
               AND deleted_at IS NULL'
        );
        $ticketsUsageStatement->execute([
            ':sub_event_id' => $subEventId,
        ]);
        $usage = $ticketsUsageStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        if ((int) ($usage['reserved_count'] ?? 0) > 0 || (int) ($usage['sold_count'] ?? 0) > 0) {
            throw new RuntimeException('Sub events with reserved or sold tickets cannot be deleted.');
        }

        $nullTicketsStatement = $pdo->prepare(
            'UPDATE tickets
             SET sub_event_id = NULL,
                 updated_at = NOW()
             WHERE sub_event_id = :sub_event_id
               AND deleted_at IS NULL'
        );
        $nullTicketsStatement->execute([
            ':sub_event_id' => $subEventId,
        ]);

        $statement = $pdo->prepare(
            'UPDATE sub_events
             SET deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :sub_event_id'
        );
        $statement->execute([
            ':sub_event_id' => $subEventId,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCountryRecord(PDO $pdo, int $countryId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM countries
                WHERE id = :country_id
                  AND deleted_at IS NULL
                LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':country_id' => $countryId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCityRecord(PDO $pdo, int $cityId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM cities
                WHERE id = :city_id
                  AND deleted_at IS NULL
                LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':city_id' => $cityId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSubEventRecord(PDO $pdo, int $subEventId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM sub_events
                WHERE id = :sub_event_id
                  AND deleted_at IS NULL
                LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':sub_event_id' => $subEventId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEventRecord(PDO $pdo, int $eventId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM events
                WHERE id = :event_id
                  AND deleted_at IS NULL
                LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':event_id' => $eventId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTicketRecord(PDO $pdo, int $ticketId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM tickets
                WHERE id = :ticket_id
                  AND deleted_at IS NULL
                LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':ticket_id' => $ticketId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validateEventPayload(PDO $pdo, array $payload, bool $isCreate, ?array $existing = null): array
    {
        $countryId = array_key_exists('country_id', $payload)
            ? $this->normalizeNullablePositiveInt($payload['country_id'])
            : $this->normalizeNullablePositiveInt($existing['country_id'] ?? null);
        if ($countryId !== null) {
            $this->ensureCountryExists($pdo, $countryId);
        }

        $title = array_key_exists('title', $payload)
            ? $this->normalizeTranslations($payload['title'], 'title')
            : $this->decodeJsonColumn($existing['title'] ?? null);
        $description = array_key_exists('description', $payload)
            ? $this->normalizeTranslations($payload['description'], 'description')
            : $this->decodeJsonColumn($existing['description'] ?? null);
        $desktopImage = array_key_exists('desktop_image', $payload)
            ? $this->normalizeRequiredString($payload['desktop_image'], 'desktop_image')
            : trim((string) ($existing['desktop_image'] ?? ''));
        $mobileImage = array_key_exists('mobile_image', $payload)
            ? $this->normalizeRequiredString($payload['mobile_image'], 'mobile_image')
            : trim((string) ($existing['mobile_image'] ?? ''));
        $date = array_key_exists('date', $payload)
            ? $this->normalizeDate($payload['date'], 'date')
            : trim((string) ($existing['date'] ?? ''));

        if ($isCreate && ($title === [] || $description === [] || $desktopImage === '' || $mobileImage === '' || $date === '')) {
            throw new RuntimeException('Missing required event fields.');
        }

        $upcoming = array_key_exists('upcoming', $payload)
            ? $this->normalizeFlag($payload['upcoming'], 'upcoming')
            : $this->inferUpcomingFromDate($date, (int) ($existing['upcoming'] ?? 1));
        $status = array_key_exists('status', $payload)
            ? $this->normalizeFlag($payload['status'], 'status')
            : (int) ($existing['status'] ?? 1);

        return [
            'country_id' => $countryId,
            'title' => $title,
            'description' => $description,
            'desktop_image' => $desktopImage,
            'mobile_image' => $mobileImage,
            'date' => $date,
            'upcoming' => $upcoming,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validateTicketPayload(
        PDO $pdo,
        int $eventId,
        array $payload,
        bool $isCreate,
        ?array $existing = null
    ): array {
        $subEventId = array_key_exists('sub_event_id', $payload)
            ? $this->normalizeNullablePositiveInt($payload['sub_event_id'])
            : $this->normalizeNullablePositiveInt($existing['sub_event_id'] ?? null);
        if ($subEventId !== null) {
            $this->ensureSubEventBelongsToEvent($pdo, $subEventId, $eventId);
        }

        $title = array_key_exists('title', $payload)
            ? $this->normalizeTranslations($payload['title'], 'title')
            : $this->decodeJsonColumn($existing['title'] ?? null);
        $price = array_key_exists('price', $payload)
            ? $this->normalizePositiveMoney($payload['price'], 'price')
            : round((float) ($existing['price'] ?? 0), 2);
        $capacity = array_key_exists('capacity', $payload)
            ? $this->normalizePositiveInt($payload['capacity'], 'capacity')
            : (int) ($existing['capacity'] ?? 0);
        $maxPerUser = array_key_exists('max_per_user', $payload)
            ? $this->normalizePositiveInt($payload['max_per_user'], 'max_per_user')
            : (int) ($existing['max_per_user'] ?? 5);
        $availableFrom = array_key_exists('available_from', $payload)
            ? $this->normalizeNullableDateTime($payload['available_from'], 'available_from')
            : $this->normalizeNullableDateTime($existing['available_from'] ?? null, 'available_from');
        $availableUntil = array_key_exists('available_until', $payload)
            ? $this->normalizeNullableDateTime($payload['available_until'], 'available_until')
            : $this->normalizeNullableDateTime($existing['available_until'] ?? null, 'available_until');
        $status = array_key_exists('status', $payload)
            ? $this->normalizeFlag($payload['status'], 'status')
            : (int) ($existing['status'] ?? 1);
        $note = array_key_exists('note', $payload)
            ? $this->normalizeNullableText($payload['note'])
            : $this->normalizeNullableText($existing['note'] ?? null);

        if ($isCreate && ($title === [] || $price <= 0 || $capacity <= 0)) {
            throw new RuntimeException('Missing required ticket fields.');
        }

        $soldCount = (int) ($existing['sold_count'] ?? 0);
        $reservedCount = (int) ($existing['reserved_count'] ?? 0);
        if ($capacity < ($soldCount + $reservedCount)) {
            throw new RuntimeException('Ticket capacity cannot be less than sold plus reserved quantities.');
        }

        if ($availableFrom !== null && $availableUntil !== null && strtotime($availableUntil) < strtotime($availableFrom)) {
            throw new RuntimeException('available_until must be greater than or equal to available_from.');
        }

        return [
            'sub_event_id' => $subEventId,
            'title' => $title,
            'price' => $price,
            'capacity' => $capacity,
            'max_per_user' => $maxPerUser,
            'available_from' => $availableFrom,
            'available_until' => $availableUntil,
            'status' => $status,
            'note' => $note,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validateCountryPayload(array $payload, bool $isCreate, ?array $existing = null): array
    {
        $name = array_key_exists('name', $payload)
            ? $this->normalizeTranslations($payload['name'], 'name')
            : $this->decodeJsonColumn($existing['name'] ?? null);
        $status = array_key_exists('status', $payload)
            ? $this->normalizeFlag($payload['status'], 'status')
            : (int) ($existing['status'] ?? 1);

        if ($isCreate && $name === []) {
            throw new RuntimeException('Missing required country fields.');
        }

        return [
            'name' => $name,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validateCityPayload(PDO $pdo, array $payload, bool $isCreate, ?array $existing = null): array
    {
        $countryId = array_key_exists('country_id', $payload)
            ? $this->normalizeNullablePositiveInt($payload['country_id'])
            : $this->normalizeNullablePositiveInt($existing['country_id'] ?? null);
        if ($countryId === null) {
            throw new RuntimeException('country_id is required.');
        }

        $this->ensureCountryExists($pdo, $countryId);

        $name = array_key_exists('name', $payload)
            ? $this->normalizeTranslations($payload['name'], 'name')
            : $this->decodeJsonColumn($existing['name'] ?? null);
        $status = array_key_exists('status', $payload)
            ? $this->normalizeFlag($payload['status'], 'status')
            : (int) ($existing['status'] ?? 1);

        if ($isCreate && $name === []) {
            throw new RuntimeException('Missing required city fields.');
        }

        return [
            'country_id' => $countryId,
            'name' => $name,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validateSubEventPayload(
        PDO $pdo,
        int $eventId,
        array $payload,
        bool $isCreate,
        ?array $existing = null
    ): array {
        $cityId = array_key_exists('city_id', $payload)
            ? $this->normalizeNullablePositiveInt($payload['city_id'])
            : $this->normalizeNullablePositiveInt($existing['city_id'] ?? null);
        if ($cityId === null) {
            throw new RuntimeException('city_id is required.');
        }
        $this->ensureCityExists($pdo, $cityId);

        $title = array_key_exists('title', $payload)
            ? $this->normalizeTranslations($payload['title'], 'title')
            : $this->decodeJsonColumn($existing['title'] ?? null);
        $subTitle = array_key_exists('sub_title', $payload)
            ? $this->normalizeNullableTranslations($payload['sub_title'], 'sub_title')
            : $this->decodeJsonColumnOrNull($existing['sub_title'] ?? null);
        $description = array_key_exists('description', $payload)
            ? $this->normalizeTranslations($payload['description'], 'description')
            : $this->decodeJsonColumn($existing['description'] ?? null);
        $location = array_key_exists('location', $payload)
            ? $this->normalizeNullableTranslations($payload['location'], 'location')
            : $this->decodeJsonColumnOrNull($existing['location'] ?? null);
        $date = array_key_exists('date', $payload)
            ? $this->normalizeDate($payload['date'], 'date')
            : trim((string) ($existing['date'] ?? ''));
        $startTime = array_key_exists('start_time', $payload)
            ? $this->normalizeTime($payload['start_time'], 'start_time')
            : trim((string) ($existing['start_time'] ?? ''));
        $endTime = array_key_exists('end_time', $payload)
            ? $this->normalizeTime($payload['end_time'], 'end_time')
            : trim((string) ($existing['end_time'] ?? ''));

        if ($isCreate && ($title === [] || $description === [] || $date === '' || $startTime === '' || $endTime === '')) {
            throw new RuntimeException('Missing required sub event fields.');
        }

        if (strtotime($date . ' ' . $endTime) <= strtotime($date . ' ' . $startTime)) {
            throw new RuntimeException('end_time must be later than start_time.');
        }

        return [
            'city_id' => $cityId,
            'title' => $title,
            'sub_title' => $subTitle,
            'description' => $description,
            'location' => $location,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
    }

    private function ensureCountryExists(PDO $pdo, int $countryId): void
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM countries
             WHERE id = :country_id
               AND deleted_at IS NULL
               AND status = 1
             LIMIT 1'
        );
        $statement->execute([
            ':country_id' => $countryId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('country_id is invalid.');
        }
    }

    private function ensureCityExists(PDO $pdo, int $cityId): void
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM cities
             WHERE id = :city_id
               AND deleted_at IS NULL
               AND status = 1
             LIMIT 1'
        );
        $statement->execute([
            ':city_id' => $cityId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('city_id is invalid.');
        }
    }

    private function ensureSubEventBelongsToEvent(PDO $pdo, int $subEventId, int $eventId): void
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM sub_events
             WHERE id = :sub_event_id
               AND event_id = :event_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            ':sub_event_id' => $subEventId,
            ':event_id' => $eventId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('sub_event_id is invalid for the selected event.');
        }
    }

    private function normalizeTranslations(mixed $value, string $field): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                throw new RuntimeException(sprintf('%s is required.', $field));
            }

            return [
                'en' => $trimmed,
                'ar' => $trimmed,
            ];
        }

        if (!is_array($value)) {
            throw new RuntimeException(sprintf('%s must be a string or object.', $field));
        }

        $translations = [];
        foreach ($value as $locale => $text) {
            if (!is_scalar($text)) {
                continue;
            }

            $normalizedText = trim((string) $text);
            if ($normalizedText === '') {
                continue;
            }

            $translations[(string) $locale] = $normalizedText;
        }

        if ($translations === []) {
            throw new RuntimeException(sprintf('%s is required.', $field));
        }

        return $translations;
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new RuntimeException(sprintf('%s is required.', $field));
        }

        return $normalized;
    }

    private function normalizeNullableTranslations(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (is_array($value) && $value === []) {
            return null;
        }

        return $this->normalizeTranslations($value, $field);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeDate(mixed $value, string $field): string
    {
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            throw new RuntimeException(sprintf('%s is invalid.', $field));
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeNullableDateTime(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            throw new RuntimeException(sprintf('%s is invalid.', $field));
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeTime(mixed $value, string $field): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new RuntimeException(sprintf('%s is required.', $field));
        }

        $timestamp = strtotime($normalized);
        if ($timestamp === false) {
            throw new RuntimeException(sprintf('%s is invalid.', $field));
        }

        return date('H:i:s', $timestamp);
    }

    private function normalizeFlag(mixed $value, string $field): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            $normalized = (int) $value;
            if (in_array($normalized, [0, 1], true)) {
                return $normalized;
            }
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        throw new RuntimeException(sprintf('%s is invalid.', $field));
    }

    private function nullableFlag(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->normalizeFlag($value, 'flag');
    }

    private function normalizePositiveMoney(mixed $value, string $field): float
    {
        $stringValue = str_replace(',', '', trim((string) $value));
        if ($stringValue === '' || !is_numeric($stringValue)) {
            throw new RuntimeException(sprintf('%s is invalid.', $field));
        }

        $amount = round((float) $stringValue, 2);
        if ($amount <= 0) {
            throw new RuntimeException(sprintf('%s must be greater than zero.', $field));
        }

        return $amount;
    }

    private function normalizePositiveInt(mixed $value, string $field): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException(sprintf('%s is invalid.', $field));
        }

        $normalized = (int) $value;
        if ($normalized <= 0) {
            throw new RuntimeException(sprintf('%s must be greater than zero.', $field));
        }

        return $normalized;
    }

    private function normalizeNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new RuntimeException('Numeric identifier is invalid.');
        }

        $normalized = (int) $value;
        if ($normalized <= 0) {
            throw new RuntimeException('Numeric identifier is invalid.');
        }

        return $normalized;
    }

    private function inferUpcomingFromDate(string $date, int $fallback): int
    {
        if ($date === '') {
            return $fallback;
        }

        return $date >= date('Y-m-d') ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapCountryRow(array $row): array
    {
        $name = $this->decodeJsonColumn($row['name'] ?? null);

        return [
            'id' => (int) $row['id'],
            'userId' => (int) $row['user_id'],
            'name' => $name,
            'nameText' => $this->resolveDisplayText($name),
            'status' => (int) $row['status'] === 1,
            'citiesCount' => isset($row['cities_count']) ? (int) $row['cities_count'] : null,
            'eventsCount' => isset($row['events_count']) ? (int) $row['events_count'] : null,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapCityRow(array $row): array
    {
        $name = $this->decodeJsonColumn($row['name'] ?? null);
        $countryName = $this->decodeJsonColumn($row['country_name'] ?? null);

        return [
            'id' => (int) $row['id'],
            'countryId' => (int) $row['country_id'],
            'userId' => (int) $row['user_id'],
            'countryName' => $countryName,
            'countryNameText' => $this->resolveDisplayText($countryName),
            'name' => $name,
            'nameText' => $this->resolveDisplayText($name),
            'status' => (int) $row['status'] === 1,
            'subEventsCount' => isset($row['sub_events_count']) ? (int) $row['sub_events_count'] : null,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapSubEventRow(array $row): array
    {
        $title = $this->decodeJsonColumn($row['title'] ?? null);
        $subTitle = $this->decodeJsonColumnOrNull($row['sub_title'] ?? null);
        $description = $this->decodeJsonColumn($row['description'] ?? null);
        $location = $this->decodeJsonColumnOrNull($row['location'] ?? null);
        $cityName = $this->decodeJsonColumn($row['city_name'] ?? null);
        $eventTitle = $this->decodeJsonColumn($row['event_title'] ?? null);

        return [
            'id' => (int) $row['id'],
            'eventId' => (int) $row['event_id'],
            'eventTitle' => $eventTitle,
            'eventTitleText' => $this->resolveDisplayText($eventTitle),
            'cityId' => (int) $row['city_id'],
            'cityName' => $cityName,
            'cityNameText' => $this->resolveDisplayText($cityName),
            'title' => $title,
            'titleText' => $this->resolveDisplayText($title),
            'subTitle' => $subTitle,
            'subTitleText' => $subTitle !== null ? $this->resolveDisplayText($subTitle) : null,
            'description' => $description,
            'descriptionText' => $this->resolveDisplayText($description),
            'location' => $location,
            'locationText' => $location !== null ? $this->resolveDisplayText($location) : null,
            'date' => (string) $row['date'],
            'startTime' => (string) $row['start_time'],
            'endTime' => (string) $row['end_time'],
            'ticketsCount' => isset($row['tickets_count']) ? (int) $row['tickets_count'] : null,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapEventRow(array $row): array
    {
        $title = $this->decodeJsonColumn($row['title'] ?? null);
        $description = $this->decodeJsonColumn($row['description'] ?? null);
        $countryName = $this->decodeJsonColumn($row['country_name'] ?? null);

        return [
            'id' => (int) $row['id'],
            'userId' => (int) $row['user_id'],
            'countryId' => $row['country_id'] !== null ? (int) $row['country_id'] : null,
            'countryName' => $countryName,
            'countryNameText' => $this->resolveDisplayText($countryName),
            'title' => $title,
            'titleText' => $this->resolveDisplayText($title),
            'description' => $description,
            'descriptionText' => $this->resolveDisplayText($description),
            'desktopImage' => (string) $row['desktop_image'],
            'mobileImage' => (string) $row['mobile_image'],
            'date' => (string) $row['date'],
            'upcoming' => (int) $row['upcoming'] === 1,
            'status' => (int) $row['status'] === 1,
            'ticketsCount' => isset($row['tickets_count']) ? (int) $row['tickets_count'] : null,
            'totalCapacity' => isset($row['total_capacity']) ? (int) $row['total_capacity'] : null,
            'soldCount' => isset($row['sold_count']) ? (int) $row['sold_count'] : null,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapTicketRow(array $row): array
    {
        $title = $this->decodeJsonColumn($row['title'] ?? null);
        $subEventTitle = $this->decodeJsonColumn($row['sub_event_title'] ?? null);
        $eventTitle = $this->decodeJsonColumn($row['event_title'] ?? null);

        return [
            'id' => (int) $row['id'],
            'eventId' => (int) $row['event_id'],
            'eventTitle' => $eventTitle,
            'eventTitleText' => $this->resolveDisplayText($eventTitle),
            'eventDate' => $row['event_date'] ?? null,
            'subEventId' => $row['sub_event_id'] !== null ? (int) $row['sub_event_id'] : null,
            'subEventTitle' => $subEventTitle,
            'subEventTitleText' => $this->resolveDisplayText($subEventTitle),
            'title' => $title,
            'titleText' => $this->resolveDisplayText($title),
            'price' => round((float) $row['price'], 2),
            'capacity' => (int) $row['capacity'],
            'reservedCount' => (int) $row['reserved_count'],
            'soldCount' => (int) $row['sold_count'],
            'remainingCount' => max(0, (int) $row['capacity'] - (int) $row['reserved_count'] - (int) $row['sold_count']),
            'maxPerUser' => (int) $row['max_per_user'],
            'availableFrom' => $row['available_from'] !== null ? (string) $row['available_from'] : null,
            'availableUntil' => $row['available_until'] !== null ? (string) $row['available_until'] : null,
            'status' => (int) $row['status'] === 1,
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonColumn(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonColumnOrNull(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function resolveDisplayText(array $translations): ?string
    {
        foreach (['en', 'ar'] as $locale) {
            $value = $translations[$locale] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        foreach ($translations as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Failed to encode JSON payload.');
        }
    }
}
