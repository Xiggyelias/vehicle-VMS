<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VehicleCrudIntegrationTest extends TestCase
{
    private ?mysqli $conn = null;
    private ?int $applicantId = null;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        try {
            $this->conn = getLegacyDatabaseConnection();
        } catch (Throwable $e) {
            $this->markTestSkipped('Database not available for integration tests: ' . $e->getMessage());
            return;
        }

        if (!$this->tableExists('applicants') || !$this->tableExists('vehicles')) {
            $this->markTestSkipped('Required tables (applicants, vehicles) are missing.');
            return;
        }

        $this->applicantId = $this->createTestApplicant();
        if (!$this->applicantId) {
            $this->markTestSkipped('Unable to create applicant fixture.');
            return;
        }

        $_SESSION['user_id'] = $this->applicantId;
        $_SESSION['user_type'] = 'student';
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
    }

    protected function tearDown(): void
    {
        if ($this->conn instanceof mysqli) {
            if ($this->applicantId !== null) {
                $this->cleanupTestData($this->applicantId);
            }
            $this->conn->close();
        }

        $_SESSION = [];
    }

    public function testVehicleCrudLifecycle(): void
    {
        if ($this->applicantId === null) {
            $this->markTestSkipped('No applicant fixture available.');
        }

        $initialReg = 'IT' . random_int(1000, 9999);
        $add = addVehicle([
            'make' => 'Toyota',
            'regNumber' => $initialReg
        ]);

        $this->assertTrue((bool)($add['success'] ?? false), $add['message'] ?? 'Add vehicle failed.');

        $vehicleId = $this->findVehicleIdByReg($initialReg);
        $this->assertNotNull($vehicleId, 'Inserted vehicle was not found.');

        $updatedReg = 'IT' . random_int(1000, 9999);
        $update = updateVehicle((int)$vehicleId, [
            'make' => 'Honda',
            'regNumber' => $updatedReg
        ]);
        $this->assertTrue((bool)($update['success'] ?? false), $update['message'] ?? 'Update vehicle failed.');

        $vehicle = getVehicleById((int)$vehicleId);
        $this->assertIsArray($vehicle);
        $this->assertSame($updatedReg, $vehicle['regNumber'] ?? null);
        $this->assertSame('Honda', $vehicle['make'] ?? null);

        $allVehicles = getUserVehicles();
        $this->assertNotEmpty($allVehicles);

        $delete = deleteVehicle((int)$vehicleId);
        $this->assertTrue((bool)($delete['success'] ?? false), $delete['message'] ?? 'Delete vehicle failed.');

        $vehicleAfterDelete = getVehicleById((int)$vehicleId);
        $this->assertNull($vehicleAfterDelete);
    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->conn->prepare('SHOW TABLES LIKE ?');
        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    private function createTestApplicant(): ?int
    {
        $columnsResult = $this->conn->query('SHOW COLUMNS FROM applicants');
        if (!$columnsResult) {
            return null;
        }

        $suffix = (string)random_int(100000, 999999);
        $email = 'integration+' . $suffix . '@example.test';
        $studentRegNo = substr($suffix, 0, 6);
        $knownValues = [
            'fullname' => 'Integration Test ' . $suffix,
            'email' => $email,
            'registranttype' => 'student',
            'password' => password_hash('Password!123', PASSWORD_DEFAULT),
            'applicationstatus' => 'approved',
            'studentregno' => $studentRegNo,
            'staffsregno' => '',
            'idnumber' => '12-345678A90',
            'phone' => '0771234567',
            'college' => 'Integration',
            'dateofbirth' => '2000-01-01',
            'registration_date' => date('Y-m-d H:i:s'),
            'last_login' => date('Y-m-d H:i:s')
        ];

        $fields = [];
        $values = [];
        $types = '';

        while ($column = $columnsResult->fetch_assoc()) {
            $field = (string)$column['Field'];
            $fieldLower = strtolower($field);
            $nullable = strtoupper((string)$column['Null']) === 'YES';
            $default = $column['Default'];
            $extra = strtolower((string)$column['Extra']);
            $type = strtolower((string)$column['Type']);

            if (str_contains($extra, 'auto_increment')) {
                continue;
            }

            if (array_key_exists($fieldLower, $knownValues)) {
                $value = $knownValues[$fieldLower];
            } elseif ($default !== null || $nullable) {
                continue;
            } else {
                $value = $this->fallbackValueForColumn($type, $suffix);
            }

            $fields[] = $field;
            $values[] = $value;
            $types .= $this->bindTypeForValue($value);
        }
        $columnsResult->free();

        if (empty($fields)) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $sql = 'INSERT INTO applicants (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param($types, ...$values);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return null;
        }

        return (int)$this->conn->insert_id;
    }

    private function fallbackValueForColumn(string $type, string $suffix)
    {
        if (preg_match("/enum\\('([^']+)'/i", $type, $match)) {
            return $match[1];
        }

        if (str_contains($type, 'int')) {
            return 0;
        }

        if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            return 0.0;
        }

        if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
            return date('Y-m-d H:i:s');
        }

        if (str_contains($type, 'date')) {
            return date('Y-m-d');
        }

        return 'integration_' . $suffix;
    }

    private function bindTypeForValue($value): string
    {
        if (is_int($value)) {
            return 'i';
        }
        if (is_float($value)) {
            return 'd';
        }
        return 's';
    }

    private function cleanupTestData(int $applicantId): void
    {
        if ($this->tableExists('authorized_driver')) {
            $driverCols = $this->getTableColumns('authorized_driver');
            if (in_array('applicant_id', $driverCols, true)) {
                $stmt = $this->conn->prepare('DELETE FROM authorized_driver WHERE applicant_id = ?');
                $stmt->bind_param('i', $applicantId);
                $stmt->execute();
                $stmt->close();
            }
        }

        $stmt = $this->conn->prepare('DELETE FROM vehicles WHERE applicant_id = ?');
        $stmt->bind_param('i', $applicantId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->conn->prepare('DELETE FROM applicants WHERE applicant_id = ?');
        $stmt->bind_param('i', $applicantId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return array<int, string>
     */
    private function getTableColumns(string $table): array
    {
        $result = $this->conn->query('SHOW COLUMNS FROM `' . $this->conn->real_escape_string($table) . '`');
        if (!$result) {
            return [];
        }

        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = (string)$row['Field'];
        }
        $result->free();
        return $columns;
    }

    private function findVehicleIdByReg(string $regNumber): ?int
    {
        $stmt = $this->conn->prepare('SELECT vehicle_id FROM vehicles WHERE applicant_id = ? AND regNumber = ? LIMIT 1');
        $stmt->bind_param('is', $this->applicantId, $regNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return (int)$row['vehicle_id'];
    }
}

