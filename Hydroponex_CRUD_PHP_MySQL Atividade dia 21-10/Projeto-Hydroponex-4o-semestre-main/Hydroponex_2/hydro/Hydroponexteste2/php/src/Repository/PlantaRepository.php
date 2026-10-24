<?php
declare(strict_types=1);
/** CRUD isolado do HTTP; todas as queries utilizam PDO prepared statements nativos. */
final class PlantaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, nome, bancada, temp, umidade, nutrientes, agua, status, last_update AS lastUpdate FROM plantas ORDER BY id');
        $stmt->execute();
        return array_map([$this, 'normalize'], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nome, bancada, temp, umidade, nutrientes, agua, status, last_update AS lastUpdate FROM plantas WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->normalize($row);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO plantas (nome, bancada, temp, nutrientes, umidade, agua, status, last_update) VALUES (?, ?, ?, ?, 60, 1.5, ?, ?)');
        $stmt->execute([$data['nome'], $data['bancada'], $data['temp'], $data['nutrientes'], 'Normal', 'Agora']);
        return $this->find((int)$this->pdo->lastInsertId());
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): ?array
    {
        $existing = $this->find($id);
        if ($existing === null) { return null; }
        $merged = array_replace($existing, $data);
        $stmt = $this->pdo->prepare('UPDATE plantas SET nome = ?, bancada = ?, temp = ?, nutrientes = ?, last_update = ? WHERE id = ?');
        $stmt->execute([$merged['nome'], $merged['bancada'], $merged['temp'], $merged['nutrientes'], 'Agora', $id]);
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM plantas WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Preserve JSON interface and actual numeric types. */
    private function normalize(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['temp'] = (float)$row['temp'];
        $row['umidade'] = (int)$row['umidade'];
        $row['nutrientes'] = (int)$row['nutrientes'];
        $row['agua'] = (float)$row['agua'];
        return $row;
    }
}
