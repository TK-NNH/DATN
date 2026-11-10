<?php

namespace App\Services\Recommender;

/**
 * Dùng các mô hình đã huấn luyện (php artisan recommender:train) để gợi ý cho chatbot.
 */
class RecommenderService
{
    public const LABELS = [
        'popularity' => 'Phổ biến nhất — gợi ý chung, giống nhau cho mọi người',
        'cf'         => 'Lọc cộng tác — phim hay được cùng nhóm người thích',
        'mf'         => 'Phân rã ma trận — phim hợp với gu riêng của người dùng',
    ];

    private static ?array $bundle = null;

    public static function modelPath(): string
    {
        return storage_path('app/recommender/models.bin');
    }

    public static function evaluationPath(): string
    {
        return storage_path('app/recommender/evaluation.json');
    }

    public function isReady(): bool
    {
        return $this->bundle() !== null;
    }

    public function userExists(int $userId): bool
    {
        $b = $this->bundle();

        return $b !== null && $b['mf']->knowsUser($userId);
    }

    /** @return array{0:int,1:int} */
    public function userRange(): array
    {
        $b = $this->bundle();

        return $b ? $b['mf']->userRange() : [0, 0];
    }

    /**
     * Gợi ý top-K phim cho user bằng mô hình $which ('popularity' | 'cf' | 'mf').
     * @return array<int,array> danh sách thẻ phim cho khung chat
     */
    public function recommend(int $userId, string $which, int $k = 5): array
    {
        $b = $this->bundle();
        if ($b === null || !isset($b[$which])) {
            return [];
        }

        $cards = [];
        foreach ($b[$which]->recommend($userId, $k) as $itemId => $score) {
            $cards[] = $this->card($b['movies'], $itemId, $score);
        }

        // Gắn ảnh poster (lấy từ TMDB, có cache)
        $posters = (new PosterService())->postersFor(array_column($cards, 'title'));
        foreach ($cards as &$card) {
            $card['poster'] = $posters[$card['title']] ?? null;
        }
        unset($card);

        return $cards;
    }

    /** Tên các phim user chấm cao nhất (để hiển thị "người này thích gì"). */
    public function likedTitles(int $userId, int $k = 3): array
    {
        $b = $this->bundle();
        if ($b === null) {
            return [];
        }
        $ratings = $b['mf']->ratingsOf($userId);
        arsort($ratings);

        $titles = [];
        foreach (array_slice($ratings, 0, $k, true) as $itemId => $r) {
            $titles[] = ($b['movies'][$itemId]['title'] ?? "Phim #{$itemId}") . " – {$r}★";
        }

        return $titles;
    }

    /** Kết quả đánh giá do lệnh recommender:evaluate ghi ra (hoặc null nếu chưa chạy). */
    public function evaluation(): ?array
    {
        $file = self::evaluationPath();
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private function card(array $movies, int $itemId, float $score): array
    {
        $m = $movies[$itemId] ?? ['title' => "Phim #{$itemId}", 'genres' => []];

        return [
            'id'     => 'ml-' . $itemId,
            'title'  => $m['title'],
            'poster' => null,              // được gắn sau bởi PosterService
            'url'    => null,              // và không có trang chi tiết trong FilmHub
            'genres' => implode(', ', $m['genres']),
            'rating' => round(min(5.0, $score), 1),  // điểm dự đoán, hiển thị tối đa 5 sao (Popularity: điểm trung bình có trọng số)
            'status' => null,
            'badge'  => null,
        ];
    }

    private function bundle(): ?array
    {
        if (self::$bundle !== null) {
            return self::$bundle;
        }
        $file = self::modelPath();
        if (!is_file($file)) {
            return null;
        }
        $data = @unserialize((string) file_get_contents($file), [
            'allowed_classes' => [PopularityModel::class, ItemKnnModel::class, MatrixFactorizationModel::class],
        ]);

        return self::$bundle = (is_array($data) && isset($data['popularity'], $data['cf'], $data['mf'], $data['movies'])) ? $data : null;
    }
}
