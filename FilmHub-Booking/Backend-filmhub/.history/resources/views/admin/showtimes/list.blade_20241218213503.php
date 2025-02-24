@extends('admin.layouts.master')

@section('title')
    Xuất chiếu
@endsection
@section('content')
<style>
    table{
        width: 100%;
    }
    td, th{
        padding: 6px 8px;
    }
    .pagination{
        justify-content: center;
        margin-top: 12px;
    }
    .areaPage{
        padding: 2px 8px;
        cursor: pointer;
        border-radius: 6px;
        box-shadow: 1px 2px 0 rgba(0,0, 0, 0.3)

    }
    .pagination .active{
        background-color: rgb(21, 189, 94);
        color:#fff
    }



</style>
   <div class="col-xl-12 col-lg-7">

            <div class="card shadow mb-4">
                <!-- Card Header - Dropdown -->
                <div
                    class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Danh sách xuất chiếu</h6>
                    <a href="{{ route("showtimes.create") }}"><button class="btn-success"> Thêm mới xuất chiếu</button></a>
                </div>
                
                @if(isset($not_delete_showtime) && $not_delete_showtime)
                    <div class="alert alert-danger" role="alert">
                        <span>Xuất chiếu có vé đặt rồi, không được phép xóa</span>
                    </div>
                @endif
                @if(isset($not_edit))
                    <div class="alert alert-danger" role="alert">
                        <span>Xuất chiếu có vé đặt rồi, không được phép sửa</span>
                    </div>
                @endif

                @if(isset($not_delete_showtime) && !$not_delete_showtime)
                    <div class="alert alert-success" role="alert">
                        <span>Xóa xuất chiếu thành công</span>
                    </div>
                @endif
                <div>
                    <form action="{{route('findShowtime')}}" method="POST">
                        @csrf
                        @method('POST')
                        <div class="card-body">
                                <input type="date" class="form-control form-control-user form-radius" name="datetime"  id="datetime" >
                                <button class="mt-2 btn-success">Tìm kiếm</button>

                            
                        </div>
                    </form>
                </div>

                <!-- Card Body -->
                <div class="card-body">
                    <table border="1" class="w-full">
                        <thead>
                            <tr>
                                <th>Id</th>
                                <th>Rạp</th>
                                <th>Phim chiếu</th>
                                <th>Phòng</th>
                                
                                <th>Giá thường</th>
                                <th>Giá Vip</th>
                                <th>Thời gian bắt đầu</th>
                                <th>Thời gian kết thúc</th>
                                <th>Ngày</th>
                                <th>Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($showtimes as $item)
                                <tr>
                                    <td>{{ $item->showtime_id  }}</td>
                                    <td>{{ $item->thearter_name }}</td>
                                    <td>{{ $item->movie_name }}</td>
                                    <td>{{ $item->room_name }}</td>
                                    <td>{{ $item->normal_price }}</td>
                                    <td>{{ $item->vip_price }}</td>
                                    <td>{{ $item->start_time }}</td>

                                    <td>{{ $item->end_time}}</td>
                                    <td>{{ $item->datetime }}</td>
                                    <td style="text-align: center">
                                        <a href="{{ route('showtimes.edit', $item->showtime_id) }}"><button class="btn-warning">Sửa</button></a>
                                        <form class="mt-2" action="{{ route('showtimes.destroy', $item->showtime_id) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                        <button class="btn-danger" onclick="return confirm('Do you want to delete this showtime ?')">Xóa</button>


                                        </form>
                                    </td>
                                </tr>

                            @endforeach


                        </tbody>
                    </table>
                    {{-- <div class="pagination">
                        @if ($showtimes->onFirstPage())
                            <span class="disabled mx-2 areaPage" >« Previous</span>
                        @else
                            <a href="{{ $showtimes->previousPageUrl() }}" class="mx-2">« Previous</a>
                        @endif

                        @foreach ($showtimes->getUrlRange(1, $showtimes->lastPage()) as $page => $url)
                            @if ($page == $showtimes->currentPage())
                                <span class="active mx-2 areaPage">{{ $page }}</span>
                            @else
                                <a href="{{ $url }} " class="mx-2 areaPage">{{ $page }}</a>
                            @endif
                        @endforeach

                        @if ($showtimes->hasMorePages())
                            <a href="{{ $showtimes->nextPageUrl() }}" class="mx-2 areaPage">Next »</a>
                        @else
                            <span class="disabled" class="mx-2 areaPage">Next »</span>
                        @endif
                    </div> --}}
                </div>
            </div>
        </div>
<script>

</script>
@endsection
