<?php

namespace App\Services\Recommender;

use RuntimeException;

/**
 * Đọc bộ dữ liệu MovieLens 100K (GroupLens):
 *   u.data : user \t item \t rating \t timestamp   (100.000 lượt chấm, 943 người dùng, 1.682 phim)
 *   u.item : id | tên | ngày | ... | URL | 19 cờ thể loại (0/1)
 *
 * Đặt các file này vào: storage/app/movielens/ml-100k/
 */
class MovieLensDataset
{
    private const GENRES = [
        'unknown', 'Action', 'Adventure', 'Animation', "Children's", 'Comedy', 'Crime', 'Documentary',
        'Drama', 'Fantasy', 'Film-Noir', 'Horror', 'Musical', 'Mystery', 'Romance', 'Sci-Fi',
        'Thriller', 'War', 'Western',
    ];

    public function __construct(private ?string $dir = null)
    {
        $this->dir = rtrim($dir ?: self::defaultDir(), '/\\');
    }

    public static function defaultDir(): string
    {
        return storage_path('app/movielens/ml-100k');
    }

    /** @return array<int, array{0:int,1:int,2:float}> các dòng [userId, itemId, rating] */
    public function ratings(): array
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . 'u.data';
        $this->assertFile($file);

        $rows = [];
        $h = fopen($file, 'r');
        while (($line = fgets($h)) !== false) {
            $p = explode("\t", trim($line));
            if (count($p) < 3) {
                continue;
            }
            $rows[] = [(int) $p[0], (int) $p[1], (float) $p[2]];
        }
        fclose($h);

        return $rows;
    }

    /** @return array<int, array{title:string, genres:array<int,string>}> theo itemId */
    public function movies(): array
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . 'u.item';
        $this->assertFile($file);

        $movies = [];
        $h = fopen($file, 'r');
        while (($line = fgets($h)) !== false) {
            $line = mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1'); // u.item mã hoá ISO-8859-1
            $p = explode('|', rtrim($line, "\r\n"));
            if (count($p) < 24) {
                continue;
            }
            $genres = [];
            foreach (self::GENRES as $g => $name) {
                if (($p[5 + $g] ?? '0') === '1') {
                    $genres[] = $name;
                }
            }
            $movies[(int) $p[0]] = [
                'title'  => self::cleanTitle(trim($p[1])),
                'genres' => $genres,
            ];
        }
        fclose($h);

        return $movies;
    }

    /**
     * Chia train/test THEO TỪNG NGƯỜI DÙNG: mỗi người giữ lại $testRatio số rating của mình làm tập test.
     * Dùng seed cố định để kết quả lặp lại được.
     *
     * @return array{0:array,1:array} [train, test]
     */
    public static function split(array $ratings, float $testRatio = 0.2, int $seed = 42): array
    {
        $byUser = [];
        foreach ($ratings as $row) {
            $byUser[$row[0]][] = $row;
        }
        ksort($byUser);

        mt_srand($seed);
        $train = [];
        $test = [];
        foreach ($byUser as $rows) {
            shuffle($rows);
            $nTest = (int) round(count($rows) * $testRatio);
            $nTest = max(1, min($nTest, count($rows) - 1));
            foreach ($rows as $idx => $row) {
                if ($idx < $nTest) {
                    $test[] = $row;
                } else {
                    $train[] = $row;
                }
            }
        }

        return [$train, $test];
    }

    /** "Usual Suspects, The (1995)" -> "The Usual Suspects (1995)" */
    private static function cleanTitle(string $title): string
    {
        return preg_replace('/^(.*), (The|A|An)( \(.*\))?$/', '$2 $1$3', $title) ?? $title;
    }

    private function assertFile(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException(
                "Không tìm thấy {$file}.\n" .
                "Tải MovieLens 100K tại https://grouplens.org/datasets/movielens/100k/ , giải nén " .
                "và đặt các file u.data, u.item vào thư mục: " . $this->dir
            );
        }
    }
}
