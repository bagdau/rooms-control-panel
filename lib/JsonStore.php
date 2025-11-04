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

    private function defaultRoomPayload(string $room, int $total = 0): array {
        $computers = [];
        for ($i = 1; $i <= $total; $i++) {
            $computers[] = [
                'id' => sprintf('%s-%03d', $room, $i),
                'status' => 'free',
                'note' => '',
                'updated_at' => date(DATE_ATOM)
            ];
        }
        return ['room' => $room, 'total' => $total, 'computers' => $computers];
    }

    public function read(string $room): array {
        $path = $this->roomPath($room);
        if (!file_exists($path)) {
            return $this->defaultRoomPayload($room, 0);
        }
        $fp = fopen($path, 'r');
        if (!$fp) return $this->defaultRoomPayload($room, 0);
        $content = stream_get_contents($fp);
        fclose($fp);
        $data = json_decode($content ?: 'null', true);
        if (!is_array($data)) {
            return $this->defaultRoomPayload($room, 0);
        }
        return $data;
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
        $currentTotal = (int)($existing['total'] ?? 0);
        $map = [];

        // сохранить уже существующие статусы по id
        foreach (($existing['computers'] ?? []) as $c) {
            $map[$c['id']] = $c;
        }

        $computers = [];
        for ($i = 1; $i <= $total; $i++) {
            $id = sprintf('%s-%03d', $room, $i);
            if (isset($map[$id])) {
                $computers[] = $map[$id];
            } else {
                $computers[] = [
                    'id' => $id,
                    'status' => 'free',
                    'note' => '',
                    'updated_at' => date(DATE_ATOM)
                ];
            }
        }

        $payload = ['room' => $room, 'total' => $total, 'computers' => $computers];
        $this->write($room, $payload);
        return $payload;
    }

    public function updateComputer(string $room, string $id, string $status, ?string $note = ''): array {
        $data = $this->read($room);
        $allowed = ['free','busy','down'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid status');
        }
        $found = false;
        foreach ($data['computers'] as &$c) {
            if ($c['id'] === $id) {
                $c['status'] = $status;
                $c['note'] = (string)$note;
                $c['updated_at'] = date(DATE_ATOM);
                $found = true;
                break;
            }
        }
        if (!$found) {
            // если ПК с таким id нет — добавим его (на случай ручного ввода)
            $data['computers'][] = [
                'id' => $id, 'status' => $status, 'note' => (string)$note, 'updated_at' => date(DATE_ATOM)
            ];
            $data['total'] = max((int)$data['total'], count($data['computers']));
        }
        $this->write($room, $data);
        return $data;
    }
}
