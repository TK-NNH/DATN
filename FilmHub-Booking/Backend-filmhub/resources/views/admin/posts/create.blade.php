@extends('admin.layouts.master')

@section('title')
    Create Post
@endsection

@section('style-libs')
    <!-- Plugins css -->
    <link href="{{ asset('theme/admin/libs/dropzone/dropzone.css') }}" rel="stylesheet" type="text/css" />
    {{-- multiple choise --}}
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-select/1.13.1/css/bootstrap-select.min.css">
    <script src="{{ asset('theme/admin/ckeditor/ckeditor.js') }}"></script>
@endsection


<style>
    .bootstrap-select .dropdown-toggle {
        height: 50px;
        border: 2px solid black;
        border-radius: 10px;
        background-color: #f8f9fa;
        color: #495057;
    }

    .bootstrap-select .dropdown-menu {
        border-radius: 10px;
        background-color: #ffffff;
        box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1);
    }

    .bootstrap-select .dropdown-menu li a {
        color: #495057;
    }

    .bootstrap-select .dropdown-menu li a:hover {
        background-color: #2855a7;
        color: white;
    }
</style>
@section('content')
    <form action="{{ route('admin.post.store') }}" enctype="multipart/form-data" method="POST">
        @csrf
        <div class="row">
            <div class="col-12">
                <div class="row">
                    <div class="col-6">
                        <div class="mb-3">
                            <label for="name" class="form-label">Tên :</label>
                            <input type="text" class="form-control" id="name" value="{{ old('name') }}" name="name">
                            @error('name')
                                <div class="text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="mb-3">
                            <label for="category_id" class="form-label">Danh mục :</label>
                            <select name="category_id" id="" class="form-control">
                                @foreach ($categories as $category)
                                    <option {{ old('category_id') == $category->id ? 'selected' : '' }} value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @error('category_id')
                                <div class="text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
            <div class="mb-3 col-12">
                <label for="avatar" class="form-label">Ảnh :</label>
                <input type="file" class="form-control" id="avatar" name="avatar">
                @error('avatar')
                    <div class="text-danger">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3 col-12">
                <label for="description" class="form-label">Mô tả :</label>
                <textarea type="text" class="form-control" id="description" name="description">{{ old('description') }}</textarea>
                @error('description')
                    <div class="text-danger">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3 col-12">
                <label for="content-post" class="form-label">Nội dung :</label>
                <textarea type="text" class="form-control tinymce_editor_init" id="content-post" name="content">{{ old('content') }}</textarea>
                @error('content')
                    <div class="text-danger">{{ $message }}</div>
                @enderror
            </div>
        </div>
        <button type="submit" class="btn btn-primary ">Save</button>
    </form>

@section('script-libs')
    <script>
        CKEDITOR.config.versionCheck = false;
        CKEDITOR.config.allowedContent = true;

        $(document).ready(function() {
            $(".tinymce_editor_init").each(function() {
                var textareaID = $(this).attr("id");
                CKEDITOR.replace(textareaID, {
                    // Loại bỏ các plugin không cần thiết để giao diện gọn hơn
                    removePlugins: 'elementspath,save',

                    // Thêm các plugin bổ sung để tăng tính năng
                    extraPlugins: 'image,justify,colorbutton',

                    // Tùy chỉnh thanh công cụ (toolbar)
                    toolbar: [{
                            name: 'basicstyles',
                            items: ['Bold', 'Italic', 'Underline', 'Strike']
                        },
                        {
                            name: 'paragraph',
                            items: ['NumberedList', 'BulletedList', 'JustifyLeft',
                                'JustifyCenter', 'JustifyRight', 'JustifyBlock'
                            ]
                        },
                        {
                            name: 'insert',
                            items: ['Image', 'Table', 'HorizontalRule', 'SpecialChar']
                        },
                        {
                            name: 'styles',
                            items: ['Format', 'Font', 'FontSize']
                        },
                        {
                            name: 'colors',
                            items: ['TextColor', 'BGColor']
                        },
                        {
                            name: 'document',
                            items: ['Source']
                        }
                    ],

                    // Cấu hình file upload
                    filebrowserUploadUrl: '/upload-handler-url', // URL xử lý upload file
                    filebrowserUploadMethod: 'form',

                    // Tắt đường dẫn phần tử ở góc dưới
                    removeButtons: 'Subscript,Superscript',

                    // Chiều cao của trình chỉnh sửa
                    height: 300
                });
            });
        });
    </script>
    <!-- ckeditor -->
    <script src="{{ asset('theme/admin/libs/@ckeditor/ckeditor5-build-classic/build/ckeditor.js') }}"></script>
    <!-- dropzone js -->
    <script src="{{ asset('theme/admin/libs/dropzone/dropzone-min.js') }}"></script>

    <script src="{{ asset('theme/admin/js/create-product.init.js') }}"></script>

    {{-- multiple choise --}}
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-select/1.13.1/js/bootstrap-select.min.js"></script>
@endsection
@endsection
