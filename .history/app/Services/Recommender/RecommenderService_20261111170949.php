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

    /**
     * Từ khóa (đã bỏ dấu, viết thường) -> thể loại MovieLens. Dùng để hiểu câu người dùng gõ,
     * ví dụ "gay cấn" -> Hành động, "hài hước" -> Hài. Muốn hiểu thêm từ nào thì thêm vào đây.
     */
    private const GENRE_KEYWORDS = [
        // Hành động
        'hanh dong' => 'Action', 'gay can' => 'Action', 'danh nhau' => 'Action', 'danh dam' => 'Action',
        'chien dau' => 'Action', 'ban nhau' => 'Action', 'kich tinh' => 'Action', 'sieu anh hung' => 'Action',
        'cao trao' => 'Action', 'mau lua' => 'Action', 'action' => 'Action',
        // Phiêu lưu
        'phieu luu' => 'Adventure', 'mao hiem' => 'Adventure', 'kham pha' => 'Adventure',
        // Hoạt hình
        'hoat hinh' => 'Animation', 'anime' => 'Animation', 'hoat hoa' => 'Animation',
        // Thiếu nhi
        'thieu nhi' => "Children's", 'tre em' => "Children's", 'tre con' => "Children's", 'gia dinh' => "Children's",
        // Hài
        'hai huoc' => 'Comedy', 'hai' => 'Comedy', 'vui nhon' => 'Comedy', 'vui ve' => 'Comedy',
        'buon cuoi' => 'Comedy', 'gay cuoi' => 'Comedy', 'tieu lam' => 'Comedy', 'comedy' => 'Comedy',
        // Tội phạm
        'toi pham' => 'Crime', 'hinh su' => 'Crime', 'bang dang' => 'Crime', 'mafia' => 'Crime',
        'trom cuop' => 'Crime', 'vu an' => 'Crime', 'dieu tra' => 'Crime', 'trinh tham' => 'Crime',
        // Tài liệu
        'tai lieu' => 'Documentary', 'documentary' => 'Documentary',
        // Chính kịch
        'chinh kich' => 'Drama', 'tam ly' => 'Drama', 'cam dong' => 'Drama', 'sau lang' => 'Drama',
        'xuc dong' => 'Drama', 'nhan van' => 'Drama', 'y nghia' => 'Drama', 'buon' => 'Drama', 'drama' => 'Drama',
        // Giả tưởng
        'gia tuong' => 'Fantasy', 'phep thuat' => 'Fantasy', 'ma thuat' => 'Fantasy', 'than thoai' => 'Fantasy',
        'phu thuy' => 'Fantasy', 'than tien' => 'Fantasy',
        // Film Noir
        'film noir' => 'Film-Noir', 'noir' => 'Film-Noir', 'den toi' => 'Film-Noir',
        // Kinh dị
        'kinh di' => 'Horror', 'phim ma' => 'Horror', 'ma quy' => 'Horror', 'con ma' => 'Horror',
        'rung ron' => 'Horror', 'so hai' => 'Horror', 'ghe ron' => 'Horror', 'zombie' => 'Horror',
        'quai vat' => 'Horror', 'horror' => 'Horror',
        // Ca nhạc
        'ca nhac' => 'Musical', 'am nhac' => 'Musical', 'nhac kich' => 'Musical', 'khieu vu' => 'Musical', 'musical' => 'Musical',
        // Bí ẩn
        'bi an' => 'Mystery', 'huyen bi' => 'Mystery', 'giai ma' => 'Mystery', 'bi mat' => 'Mystery',
        'kho doan' => 'Mystery', 'mystery' => 'Mystery',
        // Lãng mạn
        'lang man' => 'Romance', 'tinh yeu' => 'Romance', 'tinh cam' => 'Romance', 'ngon tinh' => 'Romance',
        'hen ho' => 'Romance', 'ngot ngao' => 'Romance', 'romance' => 'Romance',
        // Khoa học viễn tưởng
        'khoa hoc vien tuong' => 'Sci-Fi', 'vien tuong' => 'Sci-Fi', 'khoa hoc' => 'Sci-Fi', 'tuong lai' => 'Sci-Fi',
        'robot' => 'Sci-Fi', 'nguoi ngoai hanh tinh' => 'Sci-Fi', 'khong gian' => 'Sci-Fi', 'sci fi' => 'Sci-Fi',
        // Giật gân
        'giat gan' => 'Thriller', 'hoi hop' => 'Thriller', 'ly ky' => 'Thriller', 'cang thang' => 'Thriller',
        'nghet tho' => 'Thriller', 'thriller' => 'Thriller',
        // Chiến tranh
        'chien tranh' => 'War', 'the chien' => 'War', 'quan doi' => 'War', 'chien truong' => 'War',
        // Cao bồi
        'cao boi' => 'Western', 'mien tay' => 'Western', 'cowboy' => 'Western', 'western' => 'Western',
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

    /**
     * Tìm các thể loại được nhắc tới trong một câu đã bỏ dấu (tối đa 3), theo thứ tự xuất hiện.
     * Hiểu cả tên thể loại (tiếng Việt / tiếng Anh) lẫn các từ khóa mô tả như "gay cấn", "hài hước".
     * @return array<int,string> tên thể loại MovieLens, ví dụ ['Action', 'Comedy']
     */
    public function extractGenres(string $normalized): array
    {
        $phrases = $this->phraseMap();
        $text = ' ' . $normalized . ' ';
        $found = [];

        foreach ($phrases as $phrase => $genre) {          // cụm dài được thử trước ("khoa hoc vien tuong" trước "khoa hoc")
            $needle = ' ' . $phrase . ' ';
            $pos = strpos($text, $needle);
            if ($pos === false) {
                continue;
            }
            if (!isset($found[$genre])) {
                $found[$genre] = $pos;
            }
            $text = str_replace($needle, ' ', $text);      // bỏ cụm đã khớp để cụm ngắn hơn không khớp lại
        }

        asort($found);

        return array_slice(array_keys($found), 0, 3);
    }

    /** Tên thể loại tiếng Việt: ['Action','Comedy'] -> "Hành động + Hài". */
    public function genreNames(array $genres): string
    {
        return implode(' + ', array_map(fn ($g) => self::GENRE_VI[$g] ?? $g, $genres));
    }

    /**
     * Danh sách phim thuộc một hoặc nhiều thể loại. Phim có ĐỦ các thể loại được xếp trước,
     * sau đó đến phim chỉ có một phần; trong cùng nhóm xếp theo điểm hệ thống dự đoán người dùng sẽ chấm (trung bình).
     * @param  array<int,string> $genres
     * @return array{total:int,full:int,pages:int,page:int,cards:array}
     */
    public function topByGenres(array $genres, int $page = 1, int $perPage = 15): array
    {
        $b = $this->bundle();
        if ($b === null || !$genres) {
            return ['total' => 0, 'full' => 0, 'pages' => 0, 'page' => 1, 'cards' => []];
        }

        $rows = [];   // itemId => [số thể loại khớp, điểm trung bình]
        foreach ($this->itemScores() as $itemId => $avg) {
            $matches = count(array_intersect($genres, $b['movies'][$itemId]['genres'] ?? []));
            if ($matches > 0) {
                $rows[$itemId] = [$matches, $avg];
            }
        }
        uasort($rows, fn ($a, $b2) => [$b2[0], $b2[1]] <=> [$a[0], $a[1]]);

        $need = count($genres);
        $total = count($rows);
        $full = count(array_filter($rows, fn ($r) => $r[0] === $need));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $cards = [];
        foreach (array_slice($rows, $offset, $perPage, true) as $itemId => [$matches, $avg]) {
            $status = $b['mf']->ratingCount($itemId) . ' lượt chấm';
            if ($need > 1) {
                $status = "{$matches}/{$need} thể loại · " . $status;
            }
            $cards[] = $this->card($b['movies'], $itemId, $avg, 'Top ' . ($offset + count($cards) + 1), $status);
        }

        return ['total' => $total, 'full' => $full, 'pages' => $pages, 'page' => $page, 'cards' => $this->attachPosters($cards)];
    }

    /** Bảng "cụm từ (đã bỏ dấu) => thể loại", gồm từ khóa mô tả và tên thể loại, sắp xếp cụm dài trước. */
    private function phraseMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = self::GENRE_KEYWORDS;
            foreach (self::GENRE_VI as $en => $vi) {
                if ($en === 'unknown') {
                    continue;
                }
                $map[$this->norm($vi)] = $en;
                $map[$this->norm($en)] = $en;
            }
            uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));
        }

        return $map;
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
