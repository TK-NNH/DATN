<?php

namespace App\Console\Commands;

use App\Services\Recommender\PosterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RecommenderPosterTest extends Command
{
    protected $signature = 'recommender:poster-test {title? : Tên phim, ví dụ "Toy Story (1995)"}';

    protected $description = 'Kiểm tra API key và kết nối TMDB, thử lấy poster cho một phim';

    public function handle(): int
    {
        $title = $this->argument('title') ?: 'Toy Story (1995)';
        $key = config('services.tmdb.key');

        if (!$key) {
            $this->error('Chưa đọc được TMDB_API_KEY. Kiểm tra: (1) .env có dòng TMDB_API_KEY=..., (2) config/services.php có mục \'tmdb\', (3) đã chạy php artisan config:clear');
            return self::FAILURE;
        }
        $this->line('API key: đã cấu hình (' . strlen($key) . ' ký tự)');

        try {
            $resp = Http::timeout(8)->get('https://api.themoviedb.org/3/search/movie', [
                'api_key' => $key,
                'query'   => 'Toy Story',
            ]);
        } catch (\Throwable $e) {
            $this->error('Không kết nối được TMDB: ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'SSL certificate')) {
                $this->warn('Đây là lỗi chứng chỉ SSL trên Windows: cần cấu hình curl.cainfo và openssl.cafile trong php.ini trỏ tới file cacert.pem (tải tại curl.se/ca/cacert.pem), rồi khởi động lại Laragon.');
            }
            return self::FAILURE;
        }

        $this->line('HTTP ' . $resp->status());
        if (!$resp->ok()) {
            $this->error('TMDB từ chối: ' . ($resp->json('status_message') ?? $resp->body()) . ' (thường do sai API key)');
            return self::FAILURE;
        }
        $this->info('Kết nối TMDB thành công, trả về ' . count($resp->json('results') ?? []) . ' kết quả.');

        $poster = (new PosterService())->postersFor([$title])[$title] ?? null;
        $this->line("Poster của '{$title}': " . ($poster ?: 'không tìm thấy'));

        return self::SUCCESS;
    }
}
