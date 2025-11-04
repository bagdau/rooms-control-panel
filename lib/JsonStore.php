<?php
// lib/JsonStore.php
class JsonStore {
    private string $baseDir;

    public function __construct(string $baseDir) {
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0775, true);
        }
    }

    private function roomPath(string $room): string {
        return $this->baseDir . DIRECTORY_SEPARATOR . $room . '.json';
    }

    private function normalize(array $data, string $room): array {
        $allowedStatuses = ['free', 'busy', 'down'];

        $total = isset($data['total']) ? max(0, (int) $data['total']) : 0;
        $computers = [];

        if (isset($data['computers']) && is_array($data['computers'])) {
            foreach ($data['computers'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = isset($row['id']) ? (string) $row['id'] : '';
                if ($id === '') {
                    continue;
                }

                $status = isset($row['status']) ? (string) $row['status'] : 'free';
                if (!in_array($status, $allowedStatuses, true)) {
                    $status = 'free';
                }

                $computers[$id] = [
                    'id' => $id,
                    'status' => $status,
                    'note' => isset($row['note']) ? (string) $row['note'] : '',
                    'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : date(DATE_ATOM),
                ];
            }
        }

        if ($total < count($computers)) {
            $total = count($computers);
        }

        ksort($computers, SORT_NATURAL);

        return [
            'room' => $room,
            'total' => $total,
            'computers' => array_values($computers),
        ];
    }

    private function defaultRoomPayload(string $room, int $total = 0): array {
        $payload = ['room' => $room, 'total' => 0, 'computers' => []];
        if ($total <= 0) {
            return $payload;
        }

        $computers = [];
        for ($i = 1; $i <= $total; $i++) {
            $computers[] = [
                'id' => sprintf('%s-%03d', $room, $i),
                'status' => 'free',
                'note' => '',
                'updated_at' => date(DATE_ATOM)
            ];
        }

        $payload['total'] = $total;
        $payload['computers'] = $computers;

        return $payload;
    }

    public function read(string $room): array {
        $path = $this->roomPath($room);
        if (!file_exists($path)) {
            return $this->defaultRoomPayload($room, 0);
        }

        $fp = fopen($path, 'r');
        if (!$fp) {
            return $this->defaultRoomPayload($room, 0);
        }

        $content = stream_get_contents($fp) ?: '';
        fclose($fp);

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $this->defaultRoomPayload($room, 0);
        }

        return $this->normalize($data, $room);
    }

    public function write(string $room, array $payload): void {
        $path = $this->roomPath($room);
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $fp = fopen($path, 'c+');
        if (!$fp) throw new RuntimeException('Cannot open DB file');
        if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('Cannot lock DB file'); }

        // truncate and write
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function initRoom(string $room, int $total): array {
        $existing = $this->read($room);

        $computers = [];
        for ($i = 1; $i <= $total; $i++) {
            $id = sprintf('%s-%03d', $room, $i);
            $current = $this->findComputer($existing['computers'], $id);
            $status = isset($current['status']) ? (string) $current['status'] : 'free';
            $note = isset($current['note']) ? (string) $current['note'] : '';
            $computers[] = $this->buildComputer($id, $status, $note);
        }

        $payload = $this->normalize([
            'room' => $room,
            'total' => $total,
            'computers' => $computers,
        ], $room);

        $this->write($room, $payload);

        return $payload;
    }

    public function updateComputer(string $room, string $id, string $status, ?string $note = ''): array {
        $data = $this->read($room);

        $allowed = ['free', 'busy', 'down'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid status');
        }

        $computers = [];
        $found = false;
        foreach ($data['computers'] as $computer) {
            if ($computer['id'] === $id) {
                $computer = $this->buildComputer($id, $status, $note);
                $found = true;
            }
            $computers[$computer['id']] = $computer;
        }

        if (!$found) {
            $computers[$id] = $this->buildComputer($id, $status, $note);
        }

        $payload = $this->normalize([
            'room' => $room,
            'total' => max((int) $data['total'], count($computers)),
            'computers' => array_values($computers),
        ], $room);

        $this->write($room, $payload);

        return $payload;
    }

    public function setNote(string $room, string $id, string $note): array
    {
        $data = $this->read($room);

        $computers = [];
        $found = false;
        foreach ($data['computers'] as $computer) {
            if ($computer['id'] === $id) {
                $computer = $this->buildComputer($id, $computer['status'], $note);
                $found = true;
            }
            $computers[$computer['id']] = $computer;
        }

        if (!$found) {
            $computers[$id] = $this->buildComputer($id, 'free', $note);
        }

        $payload = $this->normalize([
            'room' => $room,
            'total' => max((int) $data['total'], count($computers)),
            'computers' => array_values($computers),
        ], $room);

        $this->write($room, $payload);

        return $payload;
    }

    public function resetRoom(string $room): array
    {
        $data = $this->read($room);

        $computers = [];
        foreach ($data['computers'] as $computer) {
            $computers[] = $this->buildComputer($computer['id'], 'free', '');
        }

        $payload = $this->normalize([
            'room' => $room,
            'total' => (int) $data['total'],
            'computers' => $computers,
        ], $room);

        $this->write($room, $payload);

        return $payload;
    }

    private function findComputer(array $computers, string $id): array
    {
        foreach ($computers as $computer) {
            if (($computer['id'] ?? null) === $id) {
                return $computer;
            }
        }

        return [];
    }

    private function buildComputer(string $id, string $status, ?string $note = ''): array
    {
        $allowed = ['free', 'busy', 'down'];
        if (!in_array($status, $allowed, true)) {
            $status = 'free';
        }

        return [
            'id' => $id,
            'status' => $status,
            'note' => (string) $note,
            'updated_at' => date(DATE_ATOM),
        ];
    }
}
