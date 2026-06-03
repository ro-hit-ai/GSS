
<?php

interface InstitutionProvider
{
    public function search(array $filters): array;
    public function findById(int $id): ?array;
    public function types(): array;
    public function states(): array;
    public function createManualSuggestion(array $data): int;
}

final class InstitutionMasterBootstrap
{
    public static function ensure(PDO $pdo): void
    {
        // Schema and dummy data are migration-owned; runtime bootstrap intentionally does nothing.
    }

    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function seedIfNeeded(PDO $pdo): void
    {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM Vati_Payfiller_Institution_Master WHERE source = 'dummy'")->fetchColumn();
        if ($count >= 500) {
            return;
        }

        $seedRows = self::buildSeedRows();
        $insert = $pdo->prepare("
            INSERT IGNORE INTO Vati_Payfiller_Institution_Master (
                institution_code, canonical_name, display_name, institution_type, institution_level,
                category, city, district, state, country, university_name, board_name, website,
                email_domain, verification_email, address, normalized_name, search_keywords,
                status, source, match_status, confidence_score, verification_supported, is_active
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                'active', 'dummy', 'verified_master', 1.00, ?, 1
            )
        ");
        $aliasInsert = $pdo->prepare("
            INSERT INTO Vati_Payfiller_Iinstitution_Aliases (institution_id, alias_name, normalized_alias)
            VALUES (?, ?, ?)
        ");
        $sourceInsert = $pdo->prepare("
            INSERT IGNORE INTO Vati_Payfiller_Institution_Source_Map (institution_id, external_source, external_id)
            VALUES (?, 'dummy_seed', ?)
        ");
        $contactInsert = $pdo->prepare("
            INSERT INTO Vati_Payfiller_Institution_Verification_Contacts (institution_id, contact_type, email, department, priority)
            VALUES (?, 'registrar', ?, 'Verification Cell', 1)
        ");

        foreach ($seedRows as $row) {
            $normalized = self::normalize($row['name']);
            $keywords = trim($normalized . ' ' . self::normalize(($row['city'] ?? '') . ' ' . ($row['state'] ?? '') . ' ' . ($row['university'] ?? '') . ' ' . ($row['board'] ?? '')));
            $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $row['name']), 0, 10)) . '-' . substr(sha1(json_encode($row)), 0, 8);
            $domain = strtolower(preg_replace('/[^a-z0-9]+/', '', substr($row['name'], 0, 18))) . '.edu';

            $insert->execute([
                $code,
                $row['name'],
                $row['name'],
                $row['type'],
                $row['level'],
                $row['category'] ?? null,
                $row['city'] ?? null,
                $row['district'] ?? null,
                $row['state'] ?? null,
                $row['country'] ?? 'India',
                $row['university'] ?? null,
                $row['board'] ?? null,
                'https://www.' . $domain,
                $domain,
                'verification@' . $domain,
                trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? ''), ', '),
                $normalized,
                $keywords,
                !empty($row['verification_supported']) ? 1 : 0,
            ]);

            $id = (int)$pdo->lastInsertId();
            if ($id <= 0) {
                $lookup = $pdo->prepare("SELECT id FROM Vati_Payfiller_Institution_Master WHERE institution_code = ? LIMIT 1");
                $lookup->execute([$code]);
                $id = (int)$lookup->fetchColumn();
            }
            if ($id <= 0) {
                continue;
            }

            foreach (($row['aliases'] ?? []) as $alias) {
                $aliasInsert->execute([$id, $alias, self::normalize($alias)]);
            }
            $sourceInsert->execute([$id, $code]);
            if (!empty($row['verification_supported'])) {
                $contactInsert->execute([$id, 'verification@' . $domain]);
            }
        }
    }

    private static function buildSeedRows(): array
    {
        $rows = [
            ['name' => 'BMS College of Engineering', 'type' => 'Engineering College', 'level' => 'undergraduate', 'category' => 'Engineering', 'city' => 'Bengaluru', 'district' => 'Bengaluru Urban', 'state' => 'Karnataka', 'university' => 'Visvesvaraya Technological University', 'aliases' => ['BMSCE', 'BMS College', 'BMS Engineering College'], 'verification_supported' => true],
            ['name' => 'BMS Institute of Technology and Management', 'type' => 'Engineering College', 'level' => 'undergraduate', 'category' => 'Engineering', 'city' => 'Bengaluru', 'district' => 'Bengaluru Urban', 'state' => 'Karnataka', 'university' => 'Visvesvaraya Technological University', 'aliases' => ['BMSIT', 'BMSITM'], 'verification_supported' => true],
            ['name' => 'RV College of Engineering', 'type' => 'Engineering College', 'level' => 'undergraduate', 'category' => 'Engineering', 'city' => 'Bengaluru', 'district' => 'Bengaluru Urban', 'state' => 'Karnataka', 'university' => 'Visvesvaraya Technological University', 'aliases' => ['RVCE', 'R V College'], 'verification_supported' => true],
            ['name' => 'PES University', 'type' => 'University', 'level' => 'undergraduate', 'category' => 'University', 'city' => 'Bengaluru', 'district' => 'Bengaluru Urban', 'state' => 'Karnataka', 'university' => 'PES University', 'aliases' => ['PESU'], 'verification_supported' => true],
            ['name' => 'Indian Institute of Science', 'type' => 'University', 'level' => 'doctoral', 'category' => 'Research', 'city' => 'Bengaluru', 'district' => 'Bengaluru Urban', 'state' => 'Karnataka', 'university' => 'Indian Institute of Science', 'aliases' => ['IISc'], 'verification_supported' => true],
            ['name' => 'Indian Institute of Technology Bombay', 'type' => 'University', 'level' => 'postgraduate', 'category' => 'IIT', 'city' => 'Mumbai', 'district' => 'Mumbai', 'state' => 'Maharashtra', 'university' => 'IIT Bombay', 'aliases' => ['IIT Bombay', 'IITB'], 'verification_supported' => true],
            ['name' => 'National Institute of Technology Karnataka Surathkal', 'type' => 'University', 'level' => 'postgraduate', 'category' => 'NIT', 'city' => 'Mangaluru', 'district' => 'Dakshina Kannada', 'state' => 'Karnataka', 'university' => 'NITK Surathkal', 'aliases' => ['NITK', 'NIT Surathkal'], 'verification_supported' => true],
            ['name' => 'Harvard University', 'type' => 'International University', 'level' => 'international', 'category' => 'International', 'city' => 'Cambridge', 'district' => 'Middlesex', 'state' => 'Massachusetts', 'country' => 'United States', 'university' => 'Harvard University', 'aliases' => ['Harvard'], 'verification_supported' => false],
        ];

        $engineeringNames = ['Acharya Institute of Technology', 'Dayananda Sagar College of Engineering', 'Nitte Meenakshi Institute of Technology', 'New Horizon College of Engineering', 'CMR Institute of Technology', 'Ramaiah Institute of Technology', 'Siddaganga Institute of Technology', 'JSS Academy of Technical Education', 'The Oxford College of Engineering', 'Global Academy of Technology'];
        $cities = [
            ['Bengaluru', 'Bengaluru Urban', 'Karnataka'],
            ['Mysuru', 'Mysuru', 'Karnataka'],
            ['Mangaluru', 'Dakshina Kannada', 'Karnataka'],
            ['Hubballi', 'Dharwad', 'Karnataka'],
            ['Belagavi', 'Belagavi', 'Karnataka'],
            ['Chennai', 'Chennai', 'Tamil Nadu'],
            ['Hyderabad', 'Hyderabad', 'Telangana'],
            ['Pune', 'Pune', 'Maharashtra'],
            ['Delhi', 'New Delhi', 'Delhi'],
            ['Kochi', 'Ernakulam', 'Kerala'],
        ];
        foreach ($engineeringNames as $name) {
            foreach (array_slice($cities, 0, 6) as $city) {
                $rows[] = [
                    'name' => $name . ' - ' . $city[0],
                    'type' => 'Engineering College',
                    'level' => 'undergraduate',
                    'category' => 'Engineering',
                    'city' => $city[0],
                    'district' => $city[1],
                    'state' => $city[2],
                    'university' => $city[2] === 'Karnataka' ? 'Visvesvaraya Technological University' : $city[2] . ' State Technical University',
                    'aliases' => [$name, preg_replace('/\b(Institute|College|Technology|Engineering|of)\b/i', '', $name)],
                    'verification_supported' => true,
                ];
            }
        }

        $boards = ['CBSE', 'ICSE', 'State Board'];
        $schoolBases = [
            'National Public School',
            'Delhi Public School',
            'Kendriya Vidyalaya',
            'St Joseph School',
            'Bishop Cotton School',
            'Ryan International School',
            'Christ School',
            'Vidya Mandir School',
            'DAV Public School',
            'Presidency School',
            'Army Public School',
            'Podar International School',
            'Vidyashilp Academy'
        ];
        foreach ($schoolBases as $base) {
            foreach ($cities as $city) {
                foreach ($boards as $board) {
                    $rows[] = [
                        'name' => $base . ' ' . $city[0] . ' ' . $board,
                        'type' => 'School',
                        'level' => 'school',
                        'category' => 'School',
                        'city' => $city[0],
                        'district' => $city[1],
                        'state' => $city[2],
                        'board' => $board,
                        'aliases' => [$base . ' ' . $city[0], $base],
                        'verification_supported' => false,
                    ];
                }
            }
        }

        $puBases = ['Government PU College', 'St Aloysius PU College', 'Christ Junior College', 'MES PU College', 'Jain PU College', 'Alvas PU College'];
        foreach ($puBases as $base) {
            foreach ($cities as $city) {
                $rows[] = [
                    'name' => $base . ' ' . $city[0],
                    'type' => 'PU College',
                    'level' => 'pre_university',
                    'category' => 'PU College',
                    'city' => $city[0],
                    'district' => $city[1],
                    'state' => $city[2],
                    'board' => $city[2] . ' Pre-University Board',
                    'aliases' => [$base, $base . ' PU'],
                    'verification_supported' => false,
                ];
            }
        }

        $universities = ['Bangalore University', 'University of Mysore', 'University of Calicut', 'Anna University', 'Osmania University', 'University of Mumbai', 'Savitribai Phule Pune University', 'Delhi University', 'Kerala University', 'Manipal Academy of Higher Education'];
        foreach ($universities as $idx => $name) {
            $city = $cities[$idx % count($cities)];
            $rows[] = [
                'name' => $name,
                'type' => strpos($name, 'Manipal') !== false ? 'Deemed University' : 'University',
                'level' => 'postgraduate',
                'category' => 'University',
                'city' => $city[0],
                'district' => $city[1],
                'state' => $city[2],
                'university' => $name,
                'aliases' => [str_replace('University', 'Univ', $name)],
                'verification_supported' => true,
            ];
        }

        $diplomaBases = ['Government Polytechnic', 'Rural Polytechnic Institute', 'City Diploma Institute', 'Technical Training Institute'];
        foreach ($diplomaBases as $base) {
            foreach ($cities as $city) {
                $rows[] = [
                    'name' => $base . ' ' . $city[0],
                    'type' => 'Diploma Institute',
                    'level' => 'diploma',
                    'category' => 'Polytechnic',
                    'city' => $city[0],
                    'district' => $city[1],
                    'state' => $city[2],
                    'university' => $city[2] . ' Directorate of Technical Education',
                    'aliases' => [$base . ' Polytechnic', $base],
                    'verification_supported' => false,
                ];
            }
        }

        return $rows;
    }
}

final class DatabaseInstitutionProvider implements InstitutionProvider
{
    public function __construct(private PDO $pdo)
    {
        InstitutionMasterBootstrap::ensure($this->pdo);
    }

    public function search(array $filters): array
    {
        $q = trim((string)($filters['q'] ?? ''));
        if (strlen($q) < 2) {
            return ['data' => [], 'page' => 1, 'limit' => 20, 'has_more' => false];
        }

        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = min(20, max(1, (int)($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $normalized = InstitutionMasterBootstrap::normalize($q);

        $where = ['im.is_active = 1', 'im.status = "active"'];
        $params = [];
        foreach (['institution_type' => 'type', 'state' => 'state', 'city' => 'city', 'country' => 'country'] as $column => $key) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = "im.$column = ?";
                $params[] = $value;
            }
        }

        $where[] = "(
            im.normalized_name LIKE ?
            OR im.display_name LIKE ?
            OR im.canonical_name LIKE ?
            OR im.city LIKE ?
            OR im.state LIKE ?
            OR im.university_name LIKE ?
            OR im.board_name LIKE ?
            OR EXISTS (
                SELECT 1 FROM Vati_Payfiller_Iinstitution_Aliases ia
                WHERE ia.institution_id = im.id
                  AND ia.normalized_alias LIKE ?
            )
        )";
        array_push($params, "%$normalized%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$normalized%");

        $sql = "
            SELECT im.id, im.display_name, im.institution_type, im.institution_level,
                   im.city, im.state, im.country, im.university_name, im.board_name,
                   im.match_status, im.verification_supported
            FROM Vati_Payfiller_Institution_Master im
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                CASE
                    WHEN im.normalized_name = ? THEN 0
                    WHEN im.normalized_name LIKE ? THEN 1
                    ELSE 2
                END,
                im.display_name ASC
            LIMIT " . ($limit + 1) . " OFFSET " . $offset;
        $params[] = $normalized;
        $params[] = $normalized . '%';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'data' => array_map([$this, 'mapRow'], $rows),
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
        ];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, display_name, institution_type, institution_level, city, state,
                   country, university_name, board_name, website, verification_email,
                   match_status, verification_supported
            FROM Vati_Payfiller_Institution_Master
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRow($row) : null;
    }

    public function types(): array
    {
        return $this->distinctList('institution_type');
    }

    public function states(): array
    {
        return $this->distinctList('state');
    }

    public function createManualSuggestion(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO Vati_Payfiller_Institution_Manual_Suggestions (
                application_id, institution_name, city, state, country,
                university_or_board, institution_type, qualification, match_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual_pending')
        ");
        $stmt->execute([
            $data['application_id'] ?? null,
            trim((string)($data['institution_name'] ?? '')),
            trim((string)($data['city'] ?? '')) ?: null,
            trim((string)($data['state'] ?? '')) ?: null,
            trim((string)($data['country'] ?? 'India')) ?: 'India',
            trim((string)($data['university_or_board'] ?? '')) ?: null,
            trim((string)($data['institution_type'] ?? '')) ?: null,
            trim((string)($data['qualification'] ?? '')) ?: null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function distinctList(string $column): array
    {
        $allowed = ['institution_type', 'state'];
        if (!in_array($column, $allowed, true)) {
            return [];
        }
        $rows = $this->pdo->query("
            SELECT DISTINCT $column AS value
            FROM Vati_Payfiller_Institution_Master
            WHERE is_active = 1 AND $column IS NOT NULL AND $column <> ''
            ORDER BY $column ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($rows));
    }

    private function mapRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => (string)($row['display_name'] ?? ''),
            'type' => (string)($row['institution_type'] ?? ''),
            'level' => (string)($row['institution_level'] ?? ''),
            'city' => (string)($row['city'] ?? ''),
            'state' => (string)($row['state'] ?? ''),
            'country' => (string)($row['country'] ?? ''),
            'university' => (string)($row['university_name'] ?? ''),
            'board' => (string)($row['board_name'] ?? ''),
            'website' => (string)($row['website'] ?? ''),
            'verified' => ($row['match_status'] ?? '') === 'verified_master',
            'verification_supported' => !empty($row['verification_supported']),
        ];
    }
}

final class InstitutionService
{
    public static function provider(PDO $pdo): InstitutionProvider
    {
        $provider = function_exists('env_get') ? env_get('INSTITUTION_PROVIDER', 'dummy') : 'dummy';
        return match ($provider) {
            'dummy', 'database', 'client_db', 'external_api' => new DatabaseInstitutionProvider($pdo),
            default => new DatabaseInstitutionProvider($pdo),
        };
    }
}
