<?php

namespace App\Services;

use App\Models\Genre;
use App\Models\Movie;
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

    private const DEFAULT_CHIPS = [
        'Đề xuất cho tôi',
        'Phim đang chiếu',
        'Phim sắp chiếu',
        'Top doanh thu tháng',
        'Theo thể loại',
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
            return $this->response('Hiện chưa có phim nào đang chiếu.', [], self::DEFAULT_CHIPS);
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
            return $this->response('Hiện chưa có thông tin phim sắp chiếu.', [], self::DEFAULT_CHIPS);
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
                "Hiện chưa có phim thể loại {$genre->name} phù hợp. Bạn thử thể loại khác nhé!",
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
            return $this->response('Hiện chưa có dữ liệu doanh thu.', [], self::DEFAULT_CHIPS);
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
            return $this->response(
                'Để đề xuất phim theo lịch sử đặt vé, bạn cần đăng nhập trước nhé. Trong lúc đó, mình gợi ý các phim đang chiếu:',
                $this->cards(
                    Movie::with('genres')->where('status', self::NOW_SHOWING)->orderByDesc('rating')->limit(self::LIMIT)->get()
                ),
                self::DEFAULT_CHIPS,
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
            return $this->response(
                'Bạn chưa có lịch sử đặt vé nên mình chưa đề xuất theo sở thích được. Mình gợi ý các phim đang chiếu nhé:',
                $this->cards(
                    Movie::with('genres')->where('status', self::NOW_SHOWING)->orderByDesc('rating')->limit(self::LIMIT)->get()
                ),
                self::DEFAULT_CHIPS
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
                "Bạn hay xem: {$summary}. Hiện chưa có phim mới cùng thể loại mà bạn chưa xem, bạn thử xem theo thể loại khác nhé!",
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

    private function response(string $text, array $movies = [], array $chips = [], array $links = []): array
    {
        return ['reply' => $text, 'movies' => $movies, 'chips' => $chips, 'links' => $links];
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
