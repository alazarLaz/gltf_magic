<!DOCTYPE html>
<html>

<head>
    <title>GLTF File Upload & Encryption</title>
</head>

<body>
    <h1>Upload GLTF File & Encrypt</h1>
    <form action="{{ route('gltf.upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name="gltf_file" accept=".gltf">
        <button type="submit">Upload & Encrypt</button>
    </form>
</body>

</html>


{{-- <!DOCTYPE html>
<html>
<head>
    <title>GLTF File Encryption</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
    <h1>GLTF File Encryption</h1>

    <form id="uploadForm" action="{{ route('upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name="gltf_file">
        <button type="submit">Upload and Encrypt</button>
    </form>

    <hr>

    <h2>Encrypted File:</h2>
    <p id="encryptedFile"></p>

    <form id="decryptForm" action="{{ route('decrypt') }}" method="POST">
        @csrf
        <input type="hidden" name="encrypted_file" id="encryptedFileInput">
        <button type="submit">Decrypt</button>
    </form>

    <script>
        $(document).ready(function() {
            $('#uploadForm').submit(function(event) {
                event.preventDefault();
                var form = $(this);
                var url = form.attr('action');
                var formData = new FormData(this);
                var csrfToken = $('meta[name="csrf-token"]').attr('content'); // Get CSRF token from meta tag

                $.ajax({
                    type: 'POST',
                    url: url,
                    data: formData,
                    processData: false,
                    contentType: false,
                    headers: {
                        'X-CSRF-TOKEN': csrfToken // Set CSRF token in request headers
                    },
                    success: function(response) {
                        $('#encryptedFile').text(response.encrypted_path);
                        $('#encryptedFileInput').val(response.encrypted_path);
                    },
                    error: function(xhr, status, error) {
                        console.error(xhr.responseText);
                    }
                });
            });
        });
    </script>
</body>
</html> --}}
