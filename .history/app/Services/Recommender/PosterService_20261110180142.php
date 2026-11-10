<?php

namespace App\Services\Recommender;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Lấy ảnh poster cho phim MovieLens từ TMDB (https://www.themoviedb.org/).
 * Cách làm: tìm theo tên phim, chọn kết quả có năm phát hành khớp (lệch tối đa 1 năm).
 * Kết quả được lưu cache nên mỗi phim chỉ gọi TMDB đúng 1 lần.
 *
 * Cần khai báo TMDB_API_KEY trong .env (xem HUONG_DAN.md). Không có key -> không có ảnh (không báo lỗi).
 */
class PosterService
{
    private const SEARCH_URL = 'https://api.themoviedb.org/3/search/movie';
    private const IMAGE_BASE = 'https://image.tmdb.org/t/p/w342';

    public function enabled(): bool
    {
        return (bool) config('services.tmdb.key');
    }

    /**
     * @param  array<int,string> $titles  tên phim dạng "Toy Story (1995)"
     * @return array<string,?string>      [tên phim => URL poster, hoặc null nếu không có]
     */
    public function postersFor(array $titles): array
    {
        $result = [];
        $missing = [];

        foreach (array_unique($titles) as $title) {
            $cached = Cache::get($this->cacheKey($title));
            if ($cached !== null) {
                $result[$title] = $cached === '' ? null : $cached;
            } else {
                $result[$title] = null;
                $missing[] = $title;
            }
        }

        if (!$missing || !$this->enabled()) {
            return $result;
        }

        // Gọi TMDB song song cho các phim chưa có trong cache
        $responses = Http::pool(function (Pool $pool) use ($missing) {
            foreach ($missing as $idx => $title) {
                [$name] = $this->parse($title);
                $pool->as((string) $idx)->timeout(5)->get(self::SEARCH_URL, [
                    'api_key'  => config('services.tmdb.key'),
                    'query'    => $name,
                    'language' => 'en-US',
                ]);
            }
        });

        foreach ($missing as $idx => $title) {
            $resp = $responses[$idx] ?? null;
            // Lỗi mạng / sai key: bỏ qua, KHÔNG lưu cache để lần sau thử lại
            if (!($resp instanceof Response) || !$resp->ok()) {
                continue;
            }

            $url = $this->pickPoster($resp->json('results') ?? [], $this->parse($title)[1]);
            Cache::forever($this->cacheKey($title), $url ?? '');   // '' = đã tìm nhưng không có poster
            $result[$title] = $url;
        }

        return $result;
    }

    /** Chọn poster của kết quả đầu tiên có năm phát hành khớp. */
    private function pickPoster(array $results, ?int $year): ?string
    {
        foreach ($results as $r) {
            if (empty($r['poster_path'])) {
                continue;
            }
            $releaseYear = (int) substr((string) ($r['release_date'] ?? ''), 0, 4);
            if ($year === null || ($releaseYear > 0 && abs($releaseYear - $year) <= 1)) {
                return self::IMAGE_BASE . $r['poster_path'];
            }
        }

        return null;
    }

    /** "Toy Story (1995)" -> ["Toy Story", 1995] */
    private function parse(string $title): array
    {
        if (preg_match('/^(.*?)\s*\((\d{4})\)\s*$/u', $title, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [$title, null];
    }

    private function cacheKey(string $title): string
    {
        return 'ml_poster_v1_' . md5($title);
    }
}
