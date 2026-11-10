<?php

namespace App\Services\Recommender;

use Illuminate\Support\Facades\Cache;

/**
 * Dùng các mô hình đã huấn luyện (php artisan recommender:train) để gợi ý cho chatbot.
 */
class RecommenderService
{
    public const LABELS = [
        'popularity' => 'Phổ biến nhất — gợi ý chung, giống nhau cho mọi người',
        'cf'         => 'Người cùng gu cũng thích — những người thích phim này thích gì thì gợi ý nấy',
        'mf'         => 'Hợp gu riêng — hệ thống tự học "gu" của từng người rồi chọn phim hợp nhất',
    ];

    /** Tên ngắn dùng trong câu trả lời. */
    public const SHORT_NAMES = [
        'popularity' => 'Phổ biến nhất',
        'cf'         => 'Người cùng gu cũng thích',
        'mf'         => 'Hợp gu riêng',
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

        return $this->attachPosters($cards);
    }

    /**
     * Top-K phim "tổng hợp cho tất cả người dùng" theo từng cách làm.
     *  - popularity: top-K theo điểm trung bình có trọng số (không phân biệt người dùng).
     *  - cf / mf: mỗi người dùng nhận một danh sách top-20; phim nào xuất hiện nhiều và ở vị trí cao
     *    trong danh sách của nhiều người nhất thì được xếp trên (bỏ phiếu có trọng số theo thứ hạng).
     *
     * @return array{users:int, cards:array}  users = số người dùng được tổng hợp (0 với popularity)
     */
    public function topOverall(string $which, int $k = 15): array
    {
        $b = $this->bundle();
        if ($b === null || !isset($b[$which])) {
            return ['users' => 0, 'cards' => []];
        }

        if ($which === 'popularity') {
            $cards = [];
            foreach ($b['popularity']->recommend(0, $k) as $itemId => $score) {   // user 0 không tồn tại => không loại phim nào
                $cards[] = $this->card($b['movies'], $itemId, $score, 'Top ' . (count($cards) + 1));
            }

            return ['users' => 0, 'cards' => $this->attachPosters($cards)];
        }

        // Tính một lần rồi lưu cache; cache tự đổi khi bạn huấn luyện lại (đổi file models.bin)
        $key = 'ml_overall_v1_' . $which . '_' . filemtime(self::modelPath());
        $agg = Cache::remember($key, 60 * 60 * 24 * 30, function () use ($b, $which) {
            $model = $b[$which];
            $listSize = 20;
            $weights = [];
            $counts = [];
            $users = $model->userIds();

            foreach ($users as $u) {
                $rank = 0;
                foreach (array_keys($model->recommend($u, $listSize)) as $itemId) {
                    $weights[$itemId] = ($weights[$itemId] ?? 0) + ($listSize - $rank);
                    $counts[$itemId] = ($counts[$itemId] ?? 0) + 1;
                    $rank++;
                }
            }
            arsort($weights);
            $top = array_slice($weights, 0, 50, true);

            return [
                'users'  => count($users),
                'top'    => $top,
                'counts' => array_intersect_key($counts, $top),
            ];
        });

        $cards = [];
        foreach (array_slice(array_keys($agg['top']), 0, $k) as $itemId) {
            $percent = $agg['users'] > 0 ? round($agg['counts'][$itemId] / $agg['users'] * 100) : 0;
            $cards[] = $this->card(
                $b['movies'], $itemId, null,
                'Top ' . (count($cards) + 1),
                "Có trong {$percent}% danh sách gợi ý"
            );
        }

        return ['users' => $agg['users'], 'cards' => $this->attachPosters($cards)];
    }

    /** Gắn ảnh poster (lấy từ TMDB, có cache) vào danh sách thẻ phim. */
    private function attachPosters(array $cards): array
    {
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

    private function card(array $movies, int $itemId, ?float $score, ?string $badge = null, ?string $status = null): array
    {
        $m = $movies[$itemId] ?? ['title' => "Phim #{$itemId}", 'genres' => []];

        return [
            'id'     => 'ml-' . $itemId,
            'title'  => $m['title'],
            'poster' => null,              // được gắn sau bởi PosterService
            'url'    => null,              // và không có trang chi tiết trong FilmHub
            'genres' => implode(', ', $m['genres']),
            'rating' => $score === null ? null : round(min(5.0, $score), 1),  // điểm dự đoán, hiển thị tối đa 5 sao (Popularity: điểm trung bình có trọng số)
            'status' => $status,
            'badge'  => $badge,
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
