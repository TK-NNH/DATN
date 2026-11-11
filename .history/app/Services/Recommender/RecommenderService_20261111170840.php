<?php

namespace App\Services\Recommender;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

    /** Tên thể loại của MovieLens -> tên tiếng Việt hiển thị. */
    public const GENRE_VI = [
        'unknown'     => 'Chưa phân loại',
        'Action'      => 'Hành động',
        'Adventure'   => 'Phiêu lưu',
        'Animation'   => 'Hoạt hình',
        "Children's"  => 'Thiếu nhi',
        'Comedy'      => 'Hài',
        'Crime'       => 'Tội phạm',
        'Documentary' => 'Tài liệu',
        'Drama'       => 'Chính kịch',
        'Fantasy'     => 'Giả tưởng',
        'Film-Noir'   => 'Film Noir',
        'Horror'      => 'Kinh dị',
        'Musical'     => 'Ca nhạc',
        'Mystery'     => 'Bí ẩn',
        'Romance'     => 'Lãng mạn',
        'Sci-Fi'      => 'Khoa học viễn tưởng',
        'Thriller'    => 'Giật gân',
        'War'         => 'Chiến tranh',
        'Western'     => 'Cao bồi',
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

    // ------------------------------------------------------------------ Gợi ý theo thể loại (dữ liệu MovieLens, tách biệt với database FilmHub)

    /** Danh sách tên thể loại tiếng Việt (bỏ "Chưa phân loại"). */
    public function genreLabels(): array
    {
        $labels = self::GENRE_VI;
        unset($labels['unknown']);

        return array_values($labels);
    }

    /** Tìm thể loại (tên MovieLens) trong câu đã được bỏ dấu; chấp nhận cả tên Việt lẫn tên Anh. */
    public function findGenre(string $normalized): ?string
    {
        $padded = ' ' . $normalized . ' ';
        $best = null;
        $bestLen = 0;

        foreach (self::GENRE_VI as $en => $vi) {
            if ($en === 'unknown') {
                continue;
            }
            foreach ([$this->norm($vi), $this->norm($en)] as $name) {
                if ($name !== '' && strlen($name) > $bestLen && str_contains($padded, ' ' . $name . ' ')) {
                    $best = $en;
                    $bestLen = strlen($name);
                }
            }
        }

        return $best;
    }

    /**
     * Danh sách phim của một thể loại, xếp theo điểm hệ thống dự đoán người dùng sẽ chấm (trung bình trên mọi người dùng).
     * @return array{total:int,pages:int,page:int,cards:array}
     */
    public function topByGenre(string $genre, int $page = 1, int $perPage = 15): array
    {
        $b = $this->bundle();
        if ($b === null) {
            return ['total' => 0, 'pages' => 0, 'page' => 1, 'cards' => []];
        }

        $ranked = [];
        foreach ($this->itemScores() as $itemId => $avg) {
            if (in_array($genre, $b['movies'][$itemId]['genres'] ?? [], true)) {
                $ranked[$itemId] = $avg;
            }
        }
        arsort($ranked);

        $total = count($ranked);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $cards = [];
        foreach (array_slice($ranked, $offset, $perPage, true) as $itemId => $avg) {
            $cards[] = $this->card(
                $b['movies'], $itemId, $avg,
                'Top ' . ($offset + count($cards) + 1),
                $b['mf']->ratingCount($itemId) . ' lượt chấm'
            );
        }

        return ['total' => $total, 'pages' => $pages, 'page' => $page, 'cards' => $this->attachPosters($cards)];
    }

    /**
     * Điểm dự đoán trung bình của từng phim (mô hình "Hợp gu riêng") trên tất cả người dùng chưa chấm phim đó.
     * Tính một lần rồi lưu cache; cache tự đổi khi huấn luyện lại.
     * @return array<int,float> [itemId => điểm trung bình]
     */
    private function itemScores(): array
    {
        $b = $this->bundle();
        $key = 'ml_item_scores_v1_' . filemtime(self::modelPath());

        return Cache::remember($key, 60 * 60 * 24 * 30, function () use ($b) {
            $mf = $b['mf'];
            $items = $mf->candidateItems();
            $sum = array_fill_keys($items, 0.0);
            $cnt = array_fill_keys($items, 0);

            foreach ($mf->userIds() as $u) {
                $rated = $mf->ratingsOf($u);
                foreach ($items as $i) {
                    if (isset($rated[$i])) {
                        continue;
                    }
                    $sum[$i] += $mf->predict($u, $i);
                    $cnt[$i]++;
                }
            }

            $avg = [];
            foreach ($items as $i) {
                if ($cnt[$i] > 0) {
                    $avg[$i] = $sum[$i] / $cnt[$i];
                }
            }

            return $avg;
        });
    }

    private function norm(string $s): string
    {
        $s = Str::of($s)->lower()->ascii()->toString();

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', $s)));
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
            'genres' => implode(', ', array_map(fn ($g) => self::GENRE_VI[$g] ?? $g, $m['genres'])),
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
