<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class PublicCatalogService
{
    /**
     * @return array<string, mixed>
     */
    public function getHomeFeed(PDO $pdo): array
    {
        $upcomingEvents = $this->getEvents($pdo, [
            'upcoming' => 1,
            'limit' => 6,
        ])['items'];

        $heroEvent = $upcomingEvents[0] ?? null;
        $countries = $this->getCountries($pdo);

        $statsStatement = $pdo->query(
            'SELECT
                (SELECT COUNT(*)
                 FROM events e
                 WHERE e.deleted_at IS NULL
                   AND e.status = 1
                   AND e.upcoming = 1) AS upcoming_events_count,
                (SELECT COUNT(*)
                 FROM countries c
                 WHERE c.deleted_at IS NULL
                   AND c.status = 1) AS active_countries_count,
                (SELECT COALESCE(SUM(GREATEST(t.capacity - t.reserved_count - t.sold_count, 0)), 0)
                 FROM tickets t
                 INNER JOIN events e ON e.id = t.event_id
                 WHERE t.deleted_at IS NULL
                   AND t.status = 1
                   AND e.deleted_at IS NULL
                   AND e.status = 1) AS available_tickets_count'
        );
        $stats = $statsStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'heroEvent' => $heroEvent,
            'upcomingEvents' => $upcomingEvents,
            'countries' => $countries['items'],
            'stats' => [
                'upcomingEventsCount' => (int) ($stats['upcoming_events_count'] ?? 0),
                'activeCountriesCount' => (int) ($stats['active_countries_count'] ?? 0),
                'availableTicketsCount' => (int) ($stats['available_tickets_count'] ?? 0),
            ],
            'checkout' => $this->getCheckoutConfiguration(),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getEvents(PDO $pdo, array $filters): array
    {
        $whereClauses = [
            'e.deleted_at IS NULL',
            'e.status = 1',
        ];
        $parameters = [];

        $upcoming = $this->nullableFlag($filters['upcoming'] ?? null);
        if ($upcoming !== null) {
            $whereClauses[] = 'e.upcoming = :upcoming';
            $parameters[':upcoming'] = $upcoming;
        }

        $countryId = $this->normalizeNullablePositiveInt($filters['country_id'] ?? null);
        if ($countryId !== null) {
            $whereClauses[] = 'e.country_id = :country_id';
            $parameters[':country_id'] = $countryId;
        }

        $cityId = $this->normalizeNullablePositiveInt($filters['city_id'] ?? null);
        if ($cityId !== null) {
            $whereClauses[] = 'EXISTS (
                SELECT 1
                FROM sub_events se_filter
                WHERE se_filter.event_id = e.id
                  AND se_filter.city_id = :city_id
                  AND se_filter.deleted_at IS NULL
            )';
            $parameters[':city_id'] = $cityId;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . $query . '%';
            $whereClauses[] = '(
                JSON_UNQUOTE(JSON_EXTRACT(e.title, "$.en")) LIKE :query_title_en
                OR JSON_UNQUOTE(JSON_EXTRACT(e.title, "$.ar")) LIKE :query_title_ar
                OR JSON_UNQUOTE(JSON_EXTRACT(c.name, "$.en")) LIKE :query_country_en
                OR JSON_UNQUOTE(JSON_EXTRACT(c.name, "$.ar")) LIKE :query_country_ar
            )';
            $parameters[':query_title_en'] = $queryValue;
            $parameters[':query_title_ar'] = $queryValue;
            $parameters[':query_country_en'] = $queryValue;
            $parameters[':query_country_ar'] = $queryValue;
        }

        $dateFrom = $this->normalizeNullableDate($filters['date_from'] ?? null, 'date_from');
        if ($dateFrom !== null) {
            $whereClauses[] = 'e.date >= :date_from';
            $parameters[':date_from'] = $dateFrom;
        }

        $dateTo = $this->normalizeNullableDate($filters['date_to'] ?? null, 'date_to');
        if ($dateTo !== null) {
            $whereClauses[] = 'e.date <= :date_to';
            $parameters[':date_to'] = $dateTo;
        }

        $limit = max(1, min(24, (int) ($filters['limit'] ?? 12)));

        $statement = $pdo->prepare(
            'SELECT
                e.id,
                e.country_id,
                e.title,
                e.description,
                e.desktop_image,
                e.mobile_image,
                e.date,
                e.upcoming,
                e.created_at,
                e.updated_at,
                c.name AS country_name,
                COUNT(DISTINCT se.id) AS sub_events_count,
                COUNT(DISTINCT t.id) AS tickets_count,
                MIN(CASE WHEN t.deleted_at IS NULL AND t.status = 1 THEN t.price END) AS min_ticket_price,
                MAX(CASE WHEN t.deleted_at IS NULL AND t.status = 1 THEN t.price END) AS max_ticket_price,
                COALESCE(SUM(CASE
                    WHEN t.deleted_at IS NULL AND t.status = 1
                    THEN GREATEST(t.capacity - t.reserved_count - t.sold_count, 0)
                    ELSE 0
                END), 0) AS remaining_tickets
             FROM events e
             LEFT JOIN countries c ON c.id = e.country_id
             LEFT JOIN sub_events se ON se.event_id = e.id AND se.deleted_at IS NULL
             LEFT JOIN tickets t ON t.event_id = e.id
             WHERE ' . implode(' AND ', $whereClauses) . '
             GROUP BY
                e.id, e.country_id, e.title, e.description, e.desktop_image, e.mobile_image,
                e.date, e.upcoming, e.created_at, e.updated_at, c.name
             ORDER BY e.date ASC, e.id DESC
             LIMIT :limit'
        );
        foreach ($parameters as $key => $value) {
            $this->bindValue($statement, $key, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map([$this, 'mapEventCardRow'], $rows),
            'filters' => [
                'upcoming' => $upcoming,
                'countryId' => $countryId,
                'cityId' => $cityId,
                'query' => $query !== '' ? $query : null,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'limit' => $limit,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEventDetails(PDO $pdo, int $eventId): ?array
    {
        $event = $this->getPublicEventRecord($pdo, $eventId);
        if ($event === null) {
            return null;
        }

        $subEvents = $this->getSubEventsByEvent($pdo, $eventId);
        $tickets = $this->getPublicTicketsByEvent($pdo, $eventId);
        $eventData = $this->mapEventDetailsRow($event);

        $prices = array_map(
            static fn (array $ticket): float => round((float) ($ticket['price'] ?? 0), 2),
            $tickets
        );
        $remainingTickets = array_sum(array_map(
            static fn (array $ticket): int => (int) ($ticket['remainingCount'] ?? 0),
            $tickets
        ));

        $eventData['subEventsCount'] = count($subEvents);
        $eventData['ticketsCount'] = count($tickets);
        $eventData['minimumPrice'] = $prices === [] ? null : min($prices);
        $eventData['maximumPrice'] = $prices === [] ? null : max($prices);
        $eventData['remainingTickets'] = $remainingTickets;

        return [
            'event' => $eventData,
            'subEvents' => $subEvents,
            'tickets' => $tickets,
            'seo' => [
                'title' => $this->resolveDisplayText($this->decodeJsonColumn($event['title'] ?? null)),
                'description' => $this->resolveDisplayText($this->decodeJsonColumn($event['description'] ?? null)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCheckoutFeed(PDO $pdo, int $eventId): ?array
    {
        $eventDetails = $this->getEventDetails($pdo, $eventId);
        if ($eventDetails === null) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $tickets */
        $tickets = is_array($eventDetails['tickets'] ?? null) ? $eventDetails['tickets'] : [];
        $availableTickets = array_values(array_filter(
            $tickets,
            static fn (array $ticket): bool => (bool) ($ticket['canCheckout'] ?? false)
        ));

        $prices = array_map(
            static fn (array $ticket): float => round((float) ($ticket['price'] ?? 0), 2),
            $availableTickets
        );

        return [
            'event' => $eventDetails['event'],
            'subEvents' => $eventDetails['subEvents'],
            'tickets' => $availableTickets,
            'checkout' => $this->getCheckoutConfiguration(),
            'summary' => [
                'availableTicketsCount' => count($availableTickets),
                'minimumPrice' => $prices === [] ? 0.0 : min($prices),
                'maximumPrice' => $prices === [] ? 0.0 : max($prices),
                'currency' => 'IQD',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCountries(PDO $pdo): array
    {
        $statement = $pdo->query(
            'SELECT
                c.id,
                c.name,
                COUNT(DISTINCT e.id) AS events_count
             FROM countries c
             INNER JOIN events e ON e.country_id = c.id
             WHERE c.deleted_at IS NULL
               AND c.status = 1
               AND e.deleted_at IS NULL
               AND e.status = 1
             GROUP BY c.id, c.name
             ORDER BY c.id ASC'
        );

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map([$this, 'mapCountryLookupRow'], $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFeaturedFeed(PDO $pdo): array
    {
        $events = $this->getEvents($pdo, [
            'upcoming' => 1,
            'limit' => 8,
        ])['items'];

        $featuredItems = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $remainingTickets = (int) ($event['remainingTickets'] ?? 0);
            $minimumPrice = $event['minimumPrice'] ?? null;

            $featuredItems[] = [
                'event' => $event,
                'score' => $this->resolveFeaturedScore($event),
                'badges' => array_values(array_filter([
                    $remainingTickets > 0 ? 'available' : null,
                    $minimumPrice !== null ? 'ticketed' : null,
                    ((bool) ($event['upcoming'] ?? false)) ? 'upcoming' : null,
                ])),
            ];
        }

        usort(
            $featuredItems,
            static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
        );

        return [
            'items' => $featuredItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTrendingFeed(PDO $pdo, int $limit = 8): array
    {
        $normalizedLimit = max(1, min(20, $limit));
        $statement = $pdo->prepare(
            'SELECT
                e.id,
                e.country_id,
                e.title,
                e.description,
                e.desktop_image,
                e.mobile_image,
                e.date,
                e.upcoming,
                e.created_at,
                e.updated_at,
                c.name AS country_name,
                COALESCE(se_aggregates.sub_events_count, 0) AS sub_events_count,
                COALESCE(ticket_aggregates.tickets_count, 0) AS tickets_count,
                ticket_aggregates.min_ticket_price,
                ticket_aggregates.max_ticket_price,
                COALESCE(ticket_aggregates.remaining_tickets, 0) AS remaining_tickets,
                COALESCE(ticket_aggregates.sold_tickets, 0) AS sold_tickets,
                COALESCE(issued_aggregates.issued_tickets, 0) AS issued_tickets
             FROM events e
             LEFT JOIN countries c ON c.id = e.country_id
             LEFT JOIN (
                SELECT
                    se.event_id,
                    COUNT(*) AS sub_events_count
                FROM sub_events se
                WHERE se.deleted_at IS NULL
                GROUP BY se.event_id
             ) AS se_aggregates ON se_aggregates.event_id = e.id
             LEFT JOIN (
                SELECT
                    t.event_id,
                    COUNT(*) AS tickets_count,
                    MIN(CASE WHEN t.status = 1 THEN t.price END) AS min_ticket_price,
                    MAX(CASE WHEN t.status = 1 THEN t.price END) AS max_ticket_price,
                    COALESCE(SUM(CASE
                        WHEN t.status = 1
                        THEN GREATEST(t.capacity - t.reserved_count - t.sold_count, 0)
                        ELSE 0
                    END), 0) AS remaining_tickets,
                    COALESCE(SUM(CASE
                        WHEN t.status = 1
                        THEN t.sold_count
                        ELSE 0
                    END), 0) AS sold_tickets
                FROM tickets t
                WHERE t.deleted_at IS NULL
                GROUP BY t.event_id
             ) AS ticket_aggregates ON ticket_aggregates.event_id = e.id
             LEFT JOIN (
                SELECT
                    t.event_id,
                    COUNT(et.id) AS issued_tickets
                FROM event_tickets et
                INNER JOIN tickets t ON t.id = et.ticket_id AND t.deleted_at IS NULL
                WHERE et.status IN ("valid", "used")
                GROUP BY t.event_id
             ) AS issued_aggregates ON issued_aggregates.event_id = e.id
             WHERE e.deleted_at IS NULL
               AND e.status = 1
             ORDER BY sold_tickets DESC, issued_tickets DESC, e.date ASC, e.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $normalizedLimit, PDO::PARAM_INT);
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map(
                fn (array $row): array => [
                    'event' => $this->mapEventCardRow($row),
                    'metrics' => [
                        'soldTickets' => (int) ($row['sold_tickets'] ?? 0),
                        'issuedTickets' => (int) ($row['issued_tickets'] ?? 0),
                    ],
                ],
                $rows
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function search(PDO $pdo, string $query, int $limit = 10): array
    {
        $normalizedQuery = trim($query);
        if ($normalizedQuery === '') {
            throw new RuntimeException('Search query is required.');
        }

        $like = '%' . $normalizedQuery . '%';
        $normalizedLimit = max(1, min(20, $limit));

        $eventsStatement = $pdo->prepare(
            'SELECT
                e.id,
                e.country_id,
                e.title,
                e.description,
                e.desktop_image,
                e.mobile_image,
                e.date,
                e.upcoming,
                e.created_at,
                e.updated_at,
                c.name AS country_name,
                COUNT(DISTINCT se.id) AS sub_events_count,
                COUNT(DISTINCT t.id) AS tickets_count,
                MIN(CASE WHEN t.deleted_at IS NULL AND t.status = 1 THEN t.price END) AS min_ticket_price,
                MAX(CASE WHEN t.deleted_at IS NULL AND t.status = 1 THEN t.price END) AS max_ticket_price,
                COALESCE(SUM(CASE
                    WHEN t.deleted_at IS NULL AND t.status = 1
                    THEN GREATEST(t.capacity - t.reserved_count - t.sold_count, 0)
                    ELSE 0
                END), 0) AS remaining_tickets
             FROM events e
             LEFT JOIN countries c ON c.id = e.country_id
             LEFT JOIN sub_events se ON se.event_id = e.id AND se.deleted_at IS NULL
             LEFT JOIN tickets t ON t.event_id = e.id
             WHERE e.deleted_at IS NULL
               AND e.status = 1
               AND (
                    JSON_UNQUOTE(JSON_EXTRACT(e.title, "$.en")) LIKE :query_title_en
                    OR JSON_UNQUOTE(JSON_EXTRACT(e.title, "$.ar")) LIKE :query_title_ar
                    OR JSON_UNQUOTE(JSON_EXTRACT(e.description, "$.en")) LIKE :query_desc_en
                    OR JSON_UNQUOTE(JSON_EXTRACT(e.description, "$.ar")) LIKE :query_desc_ar
               )
             GROUP BY
                e.id, e.country_id, e.title, e.description, e.desktop_image, e.mobile_image,
                e.date, e.upcoming, e.created_at, e.updated_at, c.name
             ORDER BY e.date ASC, e.id DESC
             LIMIT :limit'
        );
        foreach ([
            ':query_title_en' => $like,
            ':query_title_ar' => $like,
            ':query_desc_en' => $like,
            ':query_desc_ar' => $like,
        ] as $key => $value) {
            $eventsStatement->bindValue($key, $value);
        }
        $eventsStatement->bindValue(':limit', $normalizedLimit, PDO::PARAM_INT);
        $eventsStatement->execute();
        /** @var array<int, array<string, mixed>> $eventRows */
        $eventRows = $eventsStatement->fetchAll(PDO::FETCH_ASSOC);

        $subEventsStatement = $pdo->prepare(
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
                c.name AS country_name
             FROM sub_events se
             INNER JOIN cities ci ON ci.id = se.city_id
             LEFT JOIN countries c ON c.id = ci.country_id
             WHERE se.deleted_at IS NULL
               AND (
                    JSON_UNQUOTE(JSON_EXTRACT(se.title, "$.en")) LIKE :query_title_en
                    OR JSON_UNQUOTE(JSON_EXTRACT(se.title, "$.ar")) LIKE :query_title_ar
                    OR JSON_UNQUOTE(JSON_EXTRACT(se.description, "$.en")) LIKE :query_desc_en
                    OR JSON_UNQUOTE(JSON_EXTRACT(se.description, "$.ar")) LIKE :query_desc_ar
               )
             ORDER BY se.date ASC, se.id DESC
             LIMIT :limit'
        );
        foreach ([
            ':query_title_en' => $like,
            ':query_title_ar' => $like,
            ':query_desc_en' => $like,
            ':query_desc_ar' => $like,
        ] as $key => $value) {
            $subEventsStatement->bindValue($key, $value);
        }
        $subEventsStatement->bindValue(':limit', $normalizedLimit, PDO::PARAM_INT);
        $subEventsStatement->execute();
        /** @var array<int, array<string, mixed>> $subEventRows */
        $subEventRows = $subEventsStatement->fetchAll(PDO::FETCH_ASSOC);

        $ticketsStatement = $pdo->prepare(
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
                se.date AS sub_event_date,
                se.start_time AS sub_event_start_time,
                se.end_time AS sub_event_end_time,
                ci.name AS city_name
             FROM tickets t
             LEFT JOIN sub_events se ON se.id = t.sub_event_id AND se.deleted_at IS NULL
             LEFT JOIN cities ci ON ci.id = se.city_id
             WHERE t.deleted_at IS NULL
               AND t.status = 1
               AND (
                    JSON_UNQUOTE(JSON_EXTRACT(t.title, "$.en")) LIKE :query_title_en
                    OR JSON_UNQUOTE(JSON_EXTRACT(t.title, "$.ar")) LIKE :query_title_ar
                    OR t.note LIKE :query_note
               )
             ORDER BY t.price ASC, t.id ASC
             LIMIT :limit'
        );
        foreach ([
            ':query_title_en' => $like,
            ':query_title_ar' => $like,
            ':query_note' => $like,
        ] as $key => $value) {
            $ticketsStatement->bindValue($key, $value);
        }
        $ticketsStatement->bindValue(':limit', $normalizedLimit, PDO::PARAM_INT);
        $ticketsStatement->execute();
        /** @var array<int, array<string, mixed>> $ticketRows */
        $ticketRows = $ticketsStatement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'query' => $normalizedQuery,
            'results' => [
                'events' => array_map([$this, 'mapEventCardRow'], $eventRows),
                'subEvents' => array_map([$this, 'mapSubEventPublicRow'], $subEventRows),
                'tickets' => array_map([$this, 'mapPublicTicketRow'], $ticketRows),
            ],
            'totals' => [
                'events' => count($eventRows),
                'subEvents' => count($subEventRows),
                'tickets' => count($ticketRows),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getCities(PDO $pdo, array $filters): array
    {
        $whereClauses = [
            'ci.deleted_at IS NULL',
            'ci.status = 1',
        ];
        $parameters = [];

        $countryId = $this->normalizeNullablePositiveInt($filters['country_id'] ?? null);
        if ($countryId !== null) {
            $whereClauses[] = 'ci.country_id = :country_id';
            $parameters[':country_id'] = $countryId;
        }

        $statement = $pdo->prepare(
            'SELECT
                ci.id,
                ci.country_id,
                ci.name,
                c.name AS country_name,
                COUNT(DISTINCT se.id) AS sub_events_count
             FROM cities ci
             INNER JOIN countries c ON c.id = ci.country_id
             LEFT JOIN sub_events se ON se.city_id = ci.id AND se.deleted_at IS NULL
             LEFT JOIN events e ON e.id = se.event_id AND e.deleted_at IS NULL AND e.status = 1
             WHERE ' . implode(' AND ', $whereClauses) . '
             GROUP BY ci.id, ci.country_id, ci.name, c.name
             ORDER BY ci.id ASC'
        );
        foreach ($parameters as $key => $value) {
            $this->bindValue($statement, $key, $value);
        }
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map([$this, 'mapCityLookupRow'], $rows),
            'filters' => [
                'countryId' => $countryId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPublicEventRecord(PDO $pdo, int $eventId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                e.id,
                e.country_id,
                e.title,
                e.description,
                e.desktop_image,
                e.mobile_image,
                e.date,
                e.upcoming,
                e.created_at,
                e.updated_at,
                c.name AS country_name
             FROM events e
             LEFT JOIN countries c ON c.id = e.country_id
             WHERE e.id = :event_id
               AND e.deleted_at IS NULL
               AND e.status = 1
             LIMIT 1'
        );
        $statement->execute([
            ':event_id' => $eventId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSubEventsByEvent(PDO $pdo, int $eventId): array
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
                c.name AS country_name
             FROM sub_events se
             INNER JOIN cities ci ON ci.id = se.city_id
             LEFT JOIN countries c ON c.id = ci.country_id
             WHERE se.event_id = :event_id
               AND se.deleted_at IS NULL
             ORDER BY se.date ASC, se.start_time ASC, se.id ASC'
        );
        $statement->execute([
            ':event_id' => $eventId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'mapSubEventPublicRow'], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPublicTicketsByEvent(PDO $pdo, int $eventId): array
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
                se.date AS sub_event_date,
                se.start_time AS sub_event_start_time,
                se.end_time AS sub_event_end_time,
                ci.name AS city_name
             FROM tickets t
             LEFT JOIN sub_events se ON se.id = t.sub_event_id AND se.deleted_at IS NULL
             LEFT JOIN cities ci ON ci.id = se.city_id
             WHERE t.event_id = :event_id
               AND t.deleted_at IS NULL
               AND t.status = 1
             ORDER BY t.price ASC, t.id ASC'
        );
        $statement->execute([
            ':event_id' => $eventId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'mapPublicTicketRow'], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function getCheckoutConfiguration(): array
    {
        return [
            'customerFields' => [
                ['name' => 'customer_name', 'required' => true, 'type' => 'text'],
                ['name' => 'customer_phone', 'required' => true, 'type' => 'text'],
                ['name' => 'customer_email', 'required' => false, 'type' => 'email'],
                ['name' => 'customer_address', 'required' => false, 'type' => 'text'],
            ],
            'donation' => [
                'enabled' => true,
                'minimumAmount' => 0,
            ],
            'currency' => 'IQD',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapEventCardRow(array $row): array
    {
        $title = $this->decodeJsonColumn($row['title'] ?? null);
        $description = $this->decodeJsonColumn($row['description'] ?? null);
        $countryName = $this->decodeJsonColumn($row['country_name'] ?? null);

        return [
            'id' => (int) $row['id'],
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
            'upcoming' => (int) ($row['upcoming'] ?? 0) === 1,
            'subEventsCount' => (int) ($row['sub_events_count'] ?? 0),
            'ticketsCount' => (int) ($row['tickets_count'] ?? 0),
            'minimumPrice' => $row['min_ticket_price'] !== null ? round((float) $row['min_ticket_price'], 2) : null,
            'maximumPrice' => $row['max_ticket_price'] !== null ? round((float) $row['max_ticket_price'], 2) : null,
            'remainingTickets' => (int) ($row['remaining_tickets'] ?? 0),
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapEventDetailsRow(array $row): array
    {
        return $this->mapEventCardRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapCountryLookupRow(array $row): array
    {
        $name = $this->decodeJsonColumn($row['name'] ?? null);

        return [
            'id' => (int) $row['id'],
            'name' => $name,
            'nameText' => $this->resolveDisplayText($name),
            'eventsCount' => (int) ($row['events_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapCityLookupRow(array $row): array
    {
        $name = $this->decodeJsonColumn($row['name'] ?? null);
        $countryName = $this->decodeJsonColumn($row['country_name'] ?? null);

        return [
            'id' => (int) $row['id'],
            'countryId' => (int) $row['country_id'],
            'countryName' => $countryName,
            'countryNameText' => $this->resolveDisplayText($countryName),
            'name' => $name,
            'nameText' => $this->resolveDisplayText($name),
            'subEventsCount' => (int) ($row['sub_events_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapSubEventPublicRow(array $row): array
    {
        $title = $this->decodeJsonColumn($row['title'] ?? null);
        $subTitle = $this->decodeJsonColumnOrNull($row['sub_title'] ?? null);
        $description = $this->decodeJsonColumn($row['description'] ?? null);
        $location = $this->decodeJsonColumnOrNull($row['location'] ?? null);
        $cityName = $this->decodeJsonColumn($row['city_name'] ?? null);
        $countryName = $this->decodeJsonColumn($row['country_name'] ?? null);

        return [
            'id' => (int) $row['id'],
            'eventId' => (int) $row['event_id'],
            'cityId' => (int) $row['city_id'],
            'cityName' => $cityName,
            'cityNameText' => $this->resolveDisplayText($cityName),
            'countryName' => $countryName,
            'countryNameText' => $this->resolveDisplayText($countryName),
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
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapPublicTicketRow(array $row): array
    {
        $title = $this->decodeJsonColumn($row['title'] ?? null);
        $subEventTitle = $this->decodeJsonColumnOrNull($row['sub_event_title'] ?? null);
        $cityName = $this->decodeJsonColumnOrNull($row['city_name'] ?? null);

        $capacity = (int) ($row['capacity'] ?? 0);
        $reservedCount = (int) ($row['reserved_count'] ?? 0);
        $soldCount = (int) ($row['sold_count'] ?? 0);
        $remainingCount = max(0, $capacity - $reservedCount - $soldCount);
        $saleStatus = $this->resolveSaleStatus($row, $remainingCount);

        return [
            'id' => (int) $row['id'],
            'eventId' => (int) $row['event_id'],
            'subEventId' => $row['sub_event_id'] !== null ? (int) $row['sub_event_id'] : null,
            'subEventTitle' => $subEventTitle,
            'subEventTitleText' => $subEventTitle !== null ? $this->resolveDisplayText($subEventTitle) : null,
            'subEventDate' => $row['sub_event_date'] !== null ? (string) $row['sub_event_date'] : null,
            'subEventStartTime' => $row['sub_event_start_time'] !== null ? (string) $row['sub_event_start_time'] : null,
            'subEventEndTime' => $row['sub_event_end_time'] !== null ? (string) $row['sub_event_end_time'] : null,
            'cityName' => $cityName,
            'cityNameText' => $cityName !== null ? $this->resolveDisplayText($cityName) : null,
            'title' => $title,
            'titleText' => $this->resolveDisplayText($title),
            'price' => round((float) ($row['price'] ?? 0), 2),
            'capacity' => $capacity,
            'reservedCount' => $reservedCount,
            'soldCount' => $soldCount,
            'remainingCount' => $remainingCount,
            'maxPerUser' => (int) ($row['max_per_user'] ?? 0),
            'availableFrom' => $row['available_from'] !== null ? (string) $row['available_from'] : null,
            'availableUntil' => $row['available_until'] !== null ? (string) $row['available_until'] : null,
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'saleStatus' => $saleStatus['status'],
            'saleStatusMessage' => $saleStatus['message'],
            'availableNow' => $saleStatus['availableNow'],
            'canCheckout' => $saleStatus['canCheckout'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function resolveFeaturedScore(array $event): int
    {
        $score = 0;

        if ((bool) ($event['upcoming'] ?? false)) {
            $score += 30;
        }

        $remainingTickets = (int) ($event['remainingTickets'] ?? 0);
        $score += min($remainingTickets, 50);

        $minimumPrice = $event['minimumPrice'] ?? null;
        if (is_float($minimumPrice) || is_int($minimumPrice)) {
            $score += 10;
        }

        $eventDate = strtotime((string) ($event['date'] ?? ''));
        if ($eventDate !== false) {
            $daysUntilEvent = max(0, (int) floor(($eventDate - time()) / 86400));
            $score += max(0, 30 - min($daysUntilEvent, 30));
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{status:string,message:string,availableNow:bool,canCheckout:bool}
     */
    private function resolveSaleStatus(array $row, int $remainingCount): array
    {
        $now = time();
        $availableFrom = $this->toTimestamp($row['available_from'] ?? null);
        $availableUntil = $this->toTimestamp($row['available_until'] ?? null);

        if ($remainingCount <= 0) {
            return [
                'status' => 'sold_out',
                'message' => 'Sold out.',
                'availableNow' => false,
                'canCheckout' => false,
            ];
        }

        if ($availableFrom !== null && $now < $availableFrom) {
            return [
                'status' => 'coming_soon',
                'message' => 'Ticket sale has not started yet.',
                'availableNow' => false,
                'canCheckout' => false,
            ];
        }

        if ($availableUntil !== null && $now > $availableUntil) {
            return [
                'status' => 'expired',
                'message' => 'Ticket sale has ended.',
                'availableNow' => false,
                'canCheckout' => false,
            ];
        }

        return [
            'status' => 'available',
            'message' => 'Available for checkout.',
            'availableNow' => true,
            'canCheckout' => true,
        ];
    }

    private function bindValue(\PDOStatement $statement, string $key, mixed $value): void
    {
        if (is_int($value)) {
            $statement->bindValue($key, $value, PDO::PARAM_INT);
            return;
        }

        $statement->bindValue($key, $value);
    }

    private function nullableFlag(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        throw new RuntimeException('Flag value is invalid.');
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

        return $normalized > 0 ? $normalized : null;
    }

    private function normalizeNullableDate(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            throw new RuntimeException(sprintf('%s is invalid.', $field));
        }

        return date('Y-m-d', $timestamp);
    }

    private function toTimestamp(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
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

    /**
     * @param array<string, mixed> $translations
     */
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
}
