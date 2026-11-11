<?php

namespace App\Services;

use App\Models\Genre;
use App\Models\Movie;
use App\Services\Recommender\RecommenderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Chatbot gợi ý phim (rule-based, không dùng AI bên ngoài).
 * Chỉ hỗ trợ: đề xuất theo lịch sử, đang chiếu, sắp chiếu, theo thể loại, top doanh thu tháng.
 */
class MovieChatbotService
{
    private const NOW_SHOWING = 'Đang chiếu';
    private const UPCOMING    = 'Sắp ra mắt';
    private const LIMIT       = 6;
    private const NOT_FOUND_MESSAGE = 'Không tìm thấy phim phù hợp. Vui lòng thử lại.';
    private const EMPTY_MESSAGE = 'Hệ thống chưa có thông tin hoặc đang tổng hợp dữ liệu. Vui lòng thử lại sau.';

    private const DEFAULT_CHIPS = [
        'Đề xuất cho tôi',
        'Phim đang chiếu',
        'Phim sắp chiếu',
        'Top doanh thu tháng',
        'Theo thể loại',
        'Phổ biến nhất',
        'Người cùng gu cũng thích',
        'Hợp gu riêng',
        'Gợi ý thể loại',
        'Demo AI người dùng 1',
        'Kết quả đánh giá mô hình',
    ];

    /** Từ đồng nghĩa -> tên thể loại (đã bỏ dấu, viết thường). Chỉ có tác dụng nếu thể loại đó tồn tại trong DB. */
    private const GENRE_SYNONYMS = [
        'phim ma'   => 'kinh di',
        'phim hai'  => 'hai huoc',
        'anime'     => 'hoat hinh',
        'tinh cam'  => 'tam ly',
        'sci fi'    => 'vien tuong',
        'action'    => 'hanh dong',
    ];

    public function reply(string $message, $user = null): array
    {
        $n = $this->normalize($message);

        if ($n === '') {
            return $this->greeting();
        }

        // --- Demo AI (MovieLens): đánh giá mô hình / gợi ý cho một người dùng mẫu ---
        if ($this->hasAny($n, ['rmse', 'precision', 'recall', 'danh gia mo hinh', 'ket qua danh gia'])) {
            return $this->evaluationReply();
        }
        if (preg_match('/\b(?:user|nguoi dung)\s*(?:so\s*)?(\d{1,4})\b/', $n, $m)) {
            return $this->movieLensDemo((int) $m[1], $n);
        }
        // Gợi ý theo thể loại (dữ liệu MovieLens): nút "Gợi ý thể loại", "Gợi ý thể loại: Hành động [trang 2]"
        if (preg_match('/\b(?:goi y the loai|the loai ai)\b(.*)$/', $n, $gm)) {
            return $this->aiGenreReply(trim($gm[1]));
        }
        // Câu mô tả sở thích, ví dụ "Tôi thích xem phim gay cấn nhưng cũng hài hước" -> hiểu từ khóa rồi gợi ý thể loại
        if ($this->looksLikePreference($n)) {
            return $this->aiGenreReply($n);
        }
        // 3 mục tổng hợp (không gắn với người dùng cụ thể): top 15 phim của từng cách làm
        if ($this->hasAny($n, ['hop gu rieng', 'hop gu'])) {
            return $this->overallReply('mf');
        }
        if ($this->hasAny($n, ['cung gu'])) {
            return $this->overallReply('cf');
        }
        if ($this->hasAny($n, ['pho bien nhat'])) {
            return $this->overallReply('popularity');
        }

        $genre         = $this->detectGenre($n);
        $wantsRevenue  = $this->hasAny($n, ['doanh thu', 'top', 'ban chay', 'hot', 'nhieu nguoi xem', 'xem nhieu', 'pho bien']);
        $wantsUpcoming = $this->hasAny($n, ['sap chieu', 'sap ra mat', 'sap toi', 'sap ra', 'thoi gian toi']);
        $wantsNow      = $this->hasAny($n, ['dang chieu', 'hien tai', 'thang nay', 'dang co']);
        $wantsPersonal = $this->hasAny($n, ['de xuat', 'goi y', 'danh cho toi', 'cho toi', 'phu hop', 'lich su', 'toi hay', 'toi thich', 'giong phim']);

        if ($wantsRevenue && !$genre) {
            return $this->topRevenue();
        }
        if ($genre) {
            $status = $wantsUpcoming ? self::UPCOMING : ($wantsNow ? self::NOW_SHOWING : null);
            return $this->byGenre($genre, $status);
        }
        if ($wantsUpcoming) {
            return $this->upcoming();
        }
        if ($wantsNow) {
            return $this->nowShowing();
        }
        if ($wantsPersonal) {
            return $this->personal($user);
        }
        if ($this->hasAny($n, ['the loai'])) {
            return $this->genreMenu();
        }
        if ($this->hasAny($n, ['xin chao', 'chao', 'hello', 'hi', 'alo', 'help', 'giup', 'huong dan', 'lam duoc gi'])) {
            return $this->greeting();
        }

        return $this->response(
            'Mình chỉ hỗ trợ gợi ý phim thôi nhé. Bạn thử chọn một trong các gợi ý bên dưới nha 🎬',
            [],
            self::DEFAULT_CHIPS
        );
    }

    // ------------------------------------------------------------------ Các chức năng

    private function greeting(): array
    {
        return $this->response(
            'Xin chào! Mình là trợ lý gợi ý phim của FilmHub 🎬 Bạn muốn xem gì hôm nay?',
            [],
            self::DEFAULT_CHIPS
        );
    }

    private function nowShowing(): array
    {
        $movies = Movie::with('genres')
            ->where('status', self::NOW_SHOWING)
            ->orderByDesc('rating')
            ->orderBy('movie_id')
            ->limit(self::LIMIT)
            ->get();

        if ($movies->isEmpty()) {
            return $this->response(self::EMPTY_MESSAGE, [], self::DEFAULT_CHIPS);
        }

        return $this->response('Đây là các phim đang chiếu:', $this->cards($movies), self::DEFAULT_CHIPS);
    }

    private function upcoming(): array
    {
        $movies = Movie::with('genres')
            ->where('status', self::UPCOMING)
            ->orderBy('release_date')
            ->limit(self::LIMIT)
            ->get();

        if ($movies->isEmpty()) {
            return $this->response(self::EMPTY_MESSAGE, [], self::DEFAULT_CHIPS);
        }

        return $this->response('Đây là các phim sắp ra mắt:', $this->cards($movies), self::DEFAULT_CHIPS);
    }

    private function byGenre(Genre $genre, ?string $status = null): array
    {
        $statuses = $status ? [$status] : [self::NOW_SHOWING, self::UPCOMING];

        $movies = Movie::with('genres')
            ->whereHas('genres', fn ($q) => $q->where('genres.genre_id', $genre->genre_id))
            ->whereIn('status', $statuses)
            ->orderByRaw('FIELD(status, ?, ?)', [self::NOW_SHOWING, self::UPCOMING])
            ->orderByDesc('rating')
            ->limit(self::LIMIT)
            ->get();

        if ($movies->isEmpty()) {
            return $this->response(
                "Hệ thống chưa có thông tin phim thể loại {$genre->name} hoặc đang tổng hợp dữ liệu. Vui lòng thử lại sau.",
                [],
                $this->genreChips()
            );
        }

        return $this->response("Các phim thể loại {$genre->name} dành cho bạn:", $this->cards($movies), self::DEFAULT_CHIPS);
    }

    private function genreMenu(): array
    {
        return $this->response('Bạn thích thể loại nào? Chọn một thể loại bên dưới, hoặc gõ tên thể loại bất kỳ:', [], $this->genreChips());
    }

    private function topRevenue(): array
    {
        $query = fn () => DB::table('tickets')
            ->join('showtimes', 'tickets.showtime_id', '=', 'showtimes.showtime_id')
            ->where('tickets.status', 'completed')
            ->select('showtimes.movie_id', DB::raw('SUM(tickets.total_price) as revenue'))
            ->groupBy('showtimes.movie_id')
            ->orderByDesc('revenue')
            ->limit(5);

        $rows = $query()
            ->whereBetween('tickets.ticket_time', [now()->startOfMonth(), now()->endOfMonth()])
            ->get();

        $text = 'Top phim có doanh thu cao nhất tháng này:';
        if ($rows->isEmpty()) {
            // Tháng này chưa có vé nào -> lấy top mọi thời điểm để bot không bị "trống"
            $rows = $query()->get();
            $text = 'Tháng này chưa có đủ dữ liệu, đây là top phim có doanh thu cao nhất từ trước đến nay:';
        }

        if ($rows->isEmpty()) {
            return $this->response(self::EMPTY_MESSAGE, [], self::DEFAULT_CHIPS);
        }

        $movies = Movie::with('genres')->whereIn('movie_id', $rows->pluck('movie_id'))->get()->keyBy('movie_id');
        $ordered = $rows->map(fn ($r) => $movies->get($r->movie_id))->filter()->values();

        // Không hiển thị số tiền cho khách, chỉ hiển thị thứ hạng
        $cards = [];
        foreach ($ordered as $i => $movie) {
            $cards[] = $this->card($movie, 'Top ' . ($i + 1));
        }

        return $this->response($text, $cards, self::DEFAULT_CHIPS);
    }

    private function personal($user): array
    {
        if (!$user) {
            return $this->nowShowingFallback(
                'Để đề xuất phim theo lịch sử đặt vé, bạn cần đăng nhập trước nhé. Trong lúc đó, mình gợi ý các phim đang chiếu:',
                'Để đề xuất phim theo lịch sử đặt vé, bạn cần đăng nhập trước nhé.',
                [['text' => 'Đăng nhập', 'url' => route('getLogin')]]
            );
        }

        $base = fn () => DB::table('tickets')
            ->join('showtimes', 'tickets.showtime_id', '=', 'showtimes.showtime_id')
            ->where('tickets.user_id', $user->user_id)
            ->where('tickets.status', 'completed');

        $topGenres = $base()
            ->join('genre_movie', 'genre_movie.movie_id', '=', 'showtimes.movie_id')
            ->join('genres', 'genres.genre_id', '=', 'genre_movie.genre_id')
            ->select('genres.genre_id', 'genres.name', DB::raw('COUNT(DISTINCT tickets.ticket_id) as total'))
            ->groupBy('genres.genre_id', 'genres.name')
            ->orderByDesc('total')
            ->limit(3)
            ->get();

        if ($topGenres->isEmpty()) {
            return $this->nowShowingFallback(
                'Bạn chưa có lịch sử đặt vé nên mình chưa đề xuất theo sở thích được. Mình gợi ý các phim đang chiếu nhé:',
                'Bạn chưa có lịch sử đặt vé nên mình chưa đề xuất theo sở thích được. ' . self::EMPTY_MESSAGE
            );
        }

        $genreIds   = $topGenres->pluck('genre_id')->all();
        $watchedIds = $base()->distinct()->pluck('showtimes.movie_id')->all();

        $movies = Movie::with('genres')
            ->withCount(['genres as match_count' => fn ($q) => $q->whereIn('genres.genre_id', $genreIds)])
            ->whereHas('genres', fn ($q) => $q->whereIn('genres.genre_id', $genreIds))
            ->whereNotIn('movie_id', $watchedIds)
            ->whereIn('status', [self::NOW_SHOWING, self::UPCOMING])
            ->orderByDesc('match_count')
            ->orderByRaw('FIELD(status, ?, ?)', [self::NOW_SHOWING, self::UPCOMING])
            ->orderByDesc('rating')
            ->limit(self::LIMIT)
            ->get();

        $summary = $topGenres->map(fn ($g) => "{$g->name} ({$g->total} vé)")->implode(', ');

        if ($movies->isEmpty()) {
            return $this->response(
                "Bạn hay xem: {$summary}. Hệ thống chưa có phim mới cùng thể loại hoặc đang tổng hợp dữ liệu. Vui lòng thử lại sau.",
                [],
                self::DEFAULT_CHIPS
            );
        }

        return $this->response(
            "Dựa trên lịch sử đặt vé, bạn hay xem: {$summary}. Đây là các phim tương tự bạn chưa xem:",
            $this->cards($movies),
            self::DEFAULT_CHIPS
        );
    }

    // ------------------------------------------------------------------ Hỗ trợ

    private function nowShowingFallback(string $intro, string $emptyText, array $links = []): array
    {
        $movies = Movie::with('genres')
            ->where('status', self::NOW_SHOWING)
            ->orderByDesc('rating')
            ->limit(self::LIMIT)
            ->get();

        if ($movies->isEmpty()) {
            return $this->response($emptyText, [], self::DEFAULT_CHIPS, $links);
        }

        return $this->response($intro, $this->cards($movies), self::DEFAULT_CHIPS, $links);
    }

    private function response(string $text, array $movies = [], array $chips = [], array $links = [], array $sections = []): array
    {
        return ['reply' => $text, 'movies' => $movies, 'chips' => $chips, 'links' => $links, 'sections' => $sections];
    }

    // ------------------------------------------------------------------ Demo AI (MovieLens)

    /**
     * "Demo AI người dùng 25"            -> gợi ý của cả 3 thuật toán
     * "Demo AI người dùng 25 mf"         -> chỉ Matrix factorization (cf / baseline tương tự)
     */
    private function movieLensDemo(int $userId, string $n): array
    {
        $svc = new RecommenderService();

        if (!$svc->isReady()) {
            return $this->response(
                'Mô hình AI chưa được huấn luyện. Hãy chạy lệnh: php artisan recommender:train',
                [], self::DEFAULT_CHIPS
            );
        }
        if (!$svc->userExists($userId)) {
            [$min, $max] = $svc->userRange();
            return $this->response(
                "Không có người dùng số {$userId} trong dữ liệu MovieLens (hợp lệ: từ {$min} đến {$max}).",
                [], self::DEFAULT_CHIPS
            );
        }

        if ($this->hasAny($n, ['mf', 'matrix', 'svd', 'phan ra ma tran', 'hop gu'])) {
            $which = ['mf'];
        } elseif ($this->hasAny($n, ['cf', 'collaborative', 'item', 'loc cong tac', 'cung gu'])) {
            $which = ['cf'];
        } elseif ($this->hasAny($n, ['baseline', 'popularity', 'pho bien'])) {
            $which = ['popularity'];
        } else {
            $which = ['popularity', 'cf', 'mf'];
        }

        $sections = [];
        foreach ($which as $key) {
            $sections[] = [
                'title'  => RecommenderService::LABELS[$key],
                'movies' => $svc->recommend($userId, $key, 5),
            ];
        }

        $liked = implode(', ', $svc->likedTitles($userId, 3));

        $intro = count($which) > 1
            ? 'Dưới đây là các phim hệ thống gợi ý cho người này theo từng cách làm'
            : 'Dưới đây là các phim hệ thống gợi ý cho người này';

        return $this->response(
            "Người dùng mẫu số {$userId} (trong dữ liệu MovieLens) từng chấm cao: {$liked}.\n" .
            "{$intro} (số ★ là điểm mô hình dự đoán người này sẽ chấm, tối đa 5):",
            [],
            [
                "Demo AI người dùng {$userId} hợp gu riêng",
                "Demo AI người dùng {$userId} người cùng gu",
                "Demo AI người dùng {$userId} phổ biến",
                'Kết quả đánh giá mô hình',
            ],
            [],
            $sections
        );
    }

    /** Câu có dạng "tôi thích/muốn xem phim ..." hoặc nhắc tới "tình tiết"... (mô tả nội dung phim mong muốn). */
    private function looksLikePreference(string $n): bool
    {
        return (bool) preg_match('/\b(?:toi|minh|em|tui)\b.*\b(?:thich|muon|can|tim)\b.*\bphim\b/', $n)
            || (bool) preg_match('/\b(?:thich|muon) xem\b/', $n)
            || str_contains($n, 'tinh tiet');
    }

    /**
     * Gợi ý phim theo thể loại trong dữ liệu MovieLens (tách biệt với database FilmHub).
     * $rest có thể là: '' (hiện danh sách thể loại), 'hanh dong' / 'hanh dong hai trang 2' (tên thể loại),
     * hoặc cả một câu mô tả như "toi thich xem phim gay can nhung cung hai huoc".
     */
    private function aiGenreReply(string $rest): array
    {
        $svc = new RecommenderService();

        if (!$svc->isReady()) {
            return $this->response(
                'Mô hình AI chưa được huấn luyện. Hãy chạy lệnh: php artisan recommender:train',
                [], self::DEFAULT_CHIPS
            );
        }

        $page = 1;
        if (preg_match('/\btrang (\d{1,2})\s*$/', $rest, $pm)) {
            $page = (int) $pm[1];
            $rest = trim(substr($rest, 0, -strlen($pm[0])));
        }

        // Chưa nhập gì: hiện danh sách thể loại để chọn
        if ($rest === '') {
            $chips = array_map(fn ($label) => "Gợi ý thể loại: {$label}", $svc->genreLabels());

            return $this->response(
                'Bạn muốn xem phim thể loại nào? Chọn một thể loại bên dưới, hoặc gõ mô tả của bạn (ví dụ: "Tôi thích xem phim gay cấn nhưng cũng vui nhộn"):',
                [], $chips
            );
        }

        $genres = $svc->extractGenres($rest);
        if (!$genres) {
            return $this->response(
                'Không tìm thấy thể loại phù hợp với nội dung bạn nhập. Vui lòng thử lại với từ khóa khác (ví dụ: gay cấn, hài hước, lãng mạn, kinh dị, hoạt hình...).',
                [], array_merge(['Gợi ý thể loại'], array_diff(self::DEFAULT_CHIPS, ['Gợi ý thể loại']))
            );
        }

        $res = $svc->topByGenres($genres, $page, 15);
        if (empty($res['cards'])) {
            return $this->response(self::NOT_FOUND_MESSAGE, [], self::DEFAULT_CHIPS);
        }

        $names = $svc->genreNames($genres);
        if (count($genres) === 1) {
            $text = sprintf(
                'Phim thể loại %s (trang %d/%d, tổng cộng %d phim), xếp theo điểm hệ thống dự đoán người dùng sẽ chấm trung bình. Chỉ gồm các phim có đủ số lượt chấm để dự đoán đáng tin:',
                $names, $res['page'], $res['pages'], $res['total']
            );
        } elseif ($res['full'] > 0) {
            $text = sprintf(
                'Mình hiểu bạn thích thể loại: %s. Có %d phim có đủ cả các thể loại này (xếp trước), các phim sau chỉ có một phần trong số đó. Trang %d/%d:',
                $names, $res['full'], $res['page'], $res['pages']
            );
        } else {
            $text = sprintf(
                'Mình hiểu bạn thích thể loại: %s. Không có phim nào đủ cả các thể loại này, nên mình gợi ý các phim có ít nhất một thể loại (trang %d/%d):',
                $names, $res['page'], $res['pages']
            );
        }

        $labels = implode(', ', array_map(fn ($g) => RecommenderService::GENRE_VI[$g] ?? $g, $genres));
        $chips = [];
        if ($res['page'] < $res['pages']) {
            $chips[] = 'Gợi ý thể loại: ' . $labels . ' trang ' . ($res['page'] + 1);
        }
        $chips[] = 'Gợi ý thể loại';

        return $this->response($text, $res['cards'], array_merge($chips, array_diff(self::DEFAULT_CHIPS, $chips)));
    }

    /** Top 15 phim tổng hợp cho tất cả người dùng theo một cách làm: 'popularity' | 'cf' | 'mf'. */
    private function overallReply(string $which): array
    {
        $svc = new RecommenderService();

        if (!$svc->isReady()) {
            return $this->response(
                'Mô hình AI chưa được huấn luyện. Hãy chạy lệnh: php artisan recommender:train',
                [], self::DEFAULT_CHIPS
            );
        }

        $result = $svc->topOverall($which, 15);
        if (empty($result['cards'])) {
            return $this->response(self::EMPTY_MESSAGE, [], self::DEFAULT_CHIPS);
        }

        $name = RecommenderService::SHORT_NAMES[$which];
        $text = $which === 'popularity'
            ? "Top 15 phim {$name}: các phim được nhiều người chấm điểm cao nhất (gợi ý chung, không phân biệt từng người dùng):"
            : sprintf(
                'Top 15 phim theo cách "%s", tổng hợp từ danh sách gợi ý của %d người dùng MovieLens. Dưới mỗi phim là tỉ lệ người dùng được gợi ý phim đó:',
                $name, $result['users']
            );

        return $this->response($text, $result['cards'], self::DEFAULT_CHIPS);
    }

    private function evaluationReply(): array
    {
        $ev = (new RecommenderService())->evaluation();

        if ($ev === null) {
            return $this->response(
                'Chưa có kết quả đánh giá. Hãy chạy lệnh: php artisan recommender:evaluate',
                [], self::DEFAULT_CHIPS
            );
        }

        $p = $ev['params'];
        $lines = [sprintf(
            'Kết quả đánh giá trên MovieLens (học từ %d lượt chấm, kiểm tra trên %d lượt chấm khác; phim "đúng gu" là phim được chấm từ %d sao trở lên; mỗi lần gợi ý 10 phim):',
            $p['train'], $p['test'], $p['relevant']
        )];
        foreach ($ev['results'] as $r) {
            $lines[] = sprintf(
                '• %s: sai số điểm (RMSE) %.3f | độ chính xác (Precision@10) %.3f | độ phủ (Recall@10) %.3f',
                $r['model'], $r['rmse'], $r['precision'][10] ?? 0, $r['recall'][10] ?? 0
            );
        }
        $lines[] = 'Cách đọc: RMSE càng thấp thì dự đoán điểm càng sát thực tế; Precision@10 là tỉ lệ phim đúng gu trong 10 phim gợi ý; Recall@10 là tỉ lệ phim đúng gu mà hệ thống tìm ra được.';

        return $this->response(implode("\n", $lines), [], self::DEFAULT_CHIPS);
    }

    private function cards($movies): array
    {
        return $movies->map(fn ($m) => $this->card($m))->values()->all();
    }

    private function card(Movie $m, ?string $badge = null): array
    {
        return [
            'id'     => $m->movie_id,
            'title'  => $m->title,
            'poster' => $m->poster_url ? Storage::url($m->poster_url) : null,
            'url'    => route('movies.detail', $m->movie_id),
            'genres' => $m->genres->pluck('name')->implode(', '),
            'rating' => $m->rating,
            'status' => $m->status,
            'badge'  => $badge,
        ];
    }

    private function genreChips(): array
    {
        return Genre::orderBy('name')->get()
            ->filter(fn ($g) => mb_strlen($g->name) > 1)
            ->take(10)
            ->map(fn ($g) => 'Phim ' . $g->name)
            ->values()
            ->all();
    }

    private function detectGenre(string $n): ?Genre
    {
        $genres = Genre::all()->filter(fn ($g) => mb_strlen($g->name) > 1);
        $padded = ' ' . $n . ' ';

        $byName = [];
        foreach ($genres as $g) {
            $byName[$this->normalize($g->name)] = $g;
        }
        // Ưu tiên tên thể loại dài hơn (vd "khoa hoc" vs "vien tuong")
        uksort($byName, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($byName as $name => $g) {
            if (str_contains($padded, ' ' . $name . ' ')) {
                return $g;
            }
        }
        foreach (self::GENRE_SYNONYMS as $word => $target) {
            if (isset($byName[$target]) && str_contains($padded, ' ' . $word . ' ')) {
                return $byName[$target];
            }
        }

        return null;
    }

    private function normalize(string $text): string
    {
        return (string) Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim();
    }

    private function hasAny(string $n, array $keywords): bool
    {
        $padded = ' ' . $n . ' ';
        foreach ($keywords as $k) {
            if (str_contains($padded, ' ' . $k . ' ')) {
                return true;
            }
        }
        return false;
    }
}
