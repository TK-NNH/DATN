@extends('admin.layouts.master')

@section('title')
    Vourhcer
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
                    <h6 class="m-0 font-weight-bold text-primary">List Vourcher</h6>
                    <a href="{{ route("vourcher-events.create") }}"><button class="btn-success"> Add new vourcher</button></a>
                </div>
                <!-- Card Body -->
                <div class="card-body">
                    <table border="1" class="w-full">
                        <thead>
                            <tr>
                                <th>Id</th>
                                <th>Event</th>
                                <th>Code Vourcher</th>
                                <th>Mô tả</th>
                                <th>%</th>
                                <th>Tối đa</th>
                                <th>Bắt đầu</th>
                                <th>Kết thúc</th>
                                <th>Trạng thái</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($events as $item)
                            <tr>
                                <td>{{$item->id}}</td>
                                <td>{{$item->event_name}}</td>
                                <td>{{$item->vourcher_code}}</td>
                                <td>{{$item->vourcher_name}}</td>
                                <td>{{$item->discount_percentage}}%</td>
                                <td>{{$item->max_discount_amount}}</td>
                                <td>{{$item->start_time}}</td>
                                <td>{{$item->end_time}}</td>
                                <td>
                                    @if (\Carbon\Carbon::now()->greaterThan($item->end_time))
                                        <span class="text-danger">Hết hạn</span>
                                    @elseif (\Carbon\Carbon::now()->lessThan($item->start_time))
                                        <span class="text-warning">Sắp có</span>
                                    @else
                                        <span class="text-success">Còn thời hạn</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('vourcher-events.edit', $item->id) }}"><button class="btn-warning my-2">Edit</button></a>
                                    <form id="deleteForm" action="{{ route('vourcher-events.destroy', $item->id) }}" method="POST" >
                                        @csrf
                                        @method('DELETE')
                                        <button onclick="return confirm('Do you want to delete this Vourcher ?')" class="btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach;
                        </tbody>
                    </table>
                    <div class="pagination">
                        @if ($events->onFirstPage())
                            <span class="disabled mx-2 areaPage" >« Previous</span>
                        @else
                            <a href="{{ $events->previousPageUrl() }}" class="mx-2">« Previous</a>
                        @endif

                        @foreach ($events->getUrlRange(1, $events->lastPage()) as $page => $url)
                            @if ($page == $events->currentPage())
                                <span class="active mx-2 areaPage">{{ $page }}</span>
                            @else
                                <a href="{{ $url }} " class="mx-2 areaPage">{{ $page }}</a>
                            @endif
                        @endforeach

                        @if ($events->hasMorePages())
                            <a href="{{ $events->nextPageUrl() }}" class="mx-2 areaPage">Next »</a>
                        @else
                            <span class="disabled" class="mx-2 areaPage">Next »</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
<script>

</script>
@endsection
