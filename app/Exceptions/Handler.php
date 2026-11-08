<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /** Mã lỗi của request hiện tại (dùng chung cho log và thông báo hiển thị cho người dùng). */
    private ?string $errorCode = null;

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Lỗi ở giao diện web -> chuyển về trang chủ kèm thông báo + mã lỗi thay vì hiện trang lỗi.
        $this->renderable(function (Throwable $e, $request) {
            // API (JSON) giữ nguyên cách trả lỗi cũ
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }
            // Không có session thì không thể gắn thông báo
            if (!$request->hasSession()) {
                return null;
            }
            // Để Laravel tự xử lý: lỗi validate (quay lại form kèm lỗi) và chưa đăng nhập (chuyển tới trang login)
            if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
                return null;
            }
            // Chính trang chủ bị lỗi thì không chuyển hướng nữa, tránh lặp vô hạn
            if ($request->is('/')) {
                return null;
            }

            $code = $this->errorCode();

            // Lỗi 404/403... Laravel không tự ghi log -> ghi 1 dòng để vẫn tra được theo mã lỗi
            if ($this->shouldntReport($e)) {
                Log::warning("[{$code}] " . $this->describe($e), $this->context());
            }

            $flash = [
                'system_error'      => $this->friendlyMessage($e),
                'system_error_code' => $code,
            ];
            // Chi tiết kỹ thuật chỉ hiện khi APP_DEBUG=true (lúc test), người dùng thật sẽ không thấy
            if (config('app.debug')) {
                $flash['system_error_detail'] = $this->describe($e);
            }

            return redirect()->route('movies.index')->with($flash);
        });
    }

    /**
     * Thêm mã lỗi + URL vào log của lỗi được Laravel tự ghi (storage/logs/laravel.log).
     */
    protected function context(): array
    {
        return array_merge(parent::context(), [
            'error_code' => $this->errorCode(),
            'url'        => request()->fullUrl(),
            'method'     => request()->method(),
        ]);
    }

    private function errorCode(): string
    {
        return $this->errorCode ??= 'ERR-' . now()->format('ymd') . '-' . strtoupper(Str::random(6));
    }

    /** "Loại lỗi: nội dung (file:dòng)" - cho biết lỗi nằm ở đâu. */
    private function describe(Throwable $e): string
    {
        $file = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $e->getFile());

        return get_class($e) . ': ' . $e->getMessage() . " ({$file}:{$e->getLine()})";
    }

    private function friendlyMessage(Throwable $e): string
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        return match ($status) {
            403     => 'Bạn không có quyền truy cập chức năng này.',
            404     => 'Không tìm thấy nội dung bạn yêu cầu.',
            419     => 'Phiên làm việc đã hết hạn. Vui lòng thử lại.',
            429     => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau ít phút.',
            default => 'Hệ thống đang gặp sự cố. Vui lòng thử lại sau.',
        };
    }
}
