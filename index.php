<?php
// Report all errors for development environment
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Configuration ---

/**
 * @var string The domain prefix for the generated URL.
 * Should NOT include trailing slash.
 */
$domain_prefix = "i.example.com";

/**
 * @var string The local directory where files are stored.
 * MUST have write permissions and NOT include trailing slash.
 */
$upload_dir = "./i";

/**
 * @var array Allowed file extensions.
 */
$allowed_extensions = ['png', 'zip'];


// --- PHP.INI Dynamic Size Helper ---

/**
 * Converts a PHP size string (e.g., '200M', '50K') to bytes.
 * @param string $size_str The size string from ini_get().
 * @return int The size in bytes.
 */
function return_bytes(string $size_str): int {
    $size_str = trim($size_str);
    $unit = strtoupper(substr($size_str, -1));
    $value = (int)$size_str;

    switch ($unit) {
        case 'G': $value *= 1073741824; break; // 1024^3
        case 'M': $value *= 1048576; break;   // 1024^2
        case 'K': $value *= 1024; break;     // 1024^1
    }
    return $value;
}

// Read size dynamically from php.ini
$max_file_size_ini_str = ini_get('upload_max_filesize');
$max_file_size = return_bytes($max_file_size_ini_str);


// --- Dependency Checks and Status ---

$is_imagick_available = extension_loaded('imagick');
$is_fileinfo_available = extension_loaded('fileinfo');
$status_messages = [];

if (!$is_fileinfo_available) {
    $status_messages[] = "🔴 WARNING: PHP 'fileinfo' extension is missing. Cannot perform robust MIME type checking.";
}
if (!$is_imagick_available) {
    $status_messages[] = "⚠️ WARNING: PHP 'Imagick' extension is missing. PNG files cannot be processed to prevent steganography.";
}


// --- Unique Filename Helpers ---

/**
 * Generates a unique, URL-safe random string (20 characters).
 */
function generate_unique_id(int $length = 20): string {
    // Generate a secure random string and use a portion of it
    return substr(bin2hex(random_bytes($length)), 0, $length);
}

/**
 * Finds a unique filename that doesn't exist in the upload directory,
 * regardless of the file extension (Crucial for the "no two files can have the same randomized url" rule).
 */
function find_unique_base_name(string $upload_dir): string {
    $max_attempts = 10;
    for ($i = 0; $i < $max_attempts; $i++) {
        $base_name = generate_unique_id();
        // Check if ANY file with this base name exists
        $glob_pattern = $upload_dir . '/' . $base_name . '.*';
        if (empty(glob($glob_pattern))) {
            return $base_name;
        }
    }
    throw new \RuntimeException("Could not generate a unique filename after $max_attempts attempts.");
}


// --- File Upload Logic ---

$upload_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    $file = $_FILES['upload_file'];

    // 1. Basic PHP Error Check
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_message = "🔴 Upload error code: " . $file['error'];
    }

    // 2. File Size Check (Server-side)
    else if ($file['size'] > $max_file_size) {
        $upload_message = "🔴 Error: File size (" . round($file['size'] / 1048576, 2) . " MB) exceeds limit of $max_file_size_ini_str.";
    }

    // 3. Extension Check
    else {
        $file_info = pathinfo($file['name']);
        $file_ext = strtolower($file_info['extension'] ?? '');

        if (!in_array($file_ext, $allowed_extensions)) {
            $upload_message = "🔴 Error: Only " . implode(', ', $allowed_extensions) . " files are allowed.";
        }

        // 4. Mime Type Check (Requires 'fileinfo')
        else if ($is_fileinfo_available) {
            $mime_type = mime_content_type($file['tmp_name']);
            $valid_mime = false;

            if ($file_ext === 'png' && $mime_type === 'image/png') {
                $valid_mime = true;
            } elseif ($file_ext === 'zip' && in_array($mime_type, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])) {
                $valid_mime = true;
            }

            if (!$valid_mime) {
                $upload_message = "🔴 Error: Invalid file content (MIME type $mime_type).";
            }
        }
        
        // 5. Final Upload and Naming
        
        if (empty($upload_message)) {
            try {
                // Ensure the upload directory exists
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                // Generate unique base name
                $base_name = find_unique_base_name($upload_dir);
                $final_filename = $base_name . '.' . $file_ext;
                $target_path = $upload_dir . '/' . $final_filename;
                
                $upload_success = false;

                // --- PNG Processing (Steganography Prevention) ---
                if ($file_ext === 'png' && $is_imagick_available) {
                    try {
                        $image = new \Imagick($file['tmp_name']);
                        
                        // Set format explicitly to PNG (essential for cleaning)
                        $image->setImageFormat('png'); 
                        
                        // Strip all embedded metadata (EXIF, IPTC, comments, etc.)
                        $image->stripImage(); 
                        
                        // Write the re-encoded, cleaned image to the target path
                        if ($image->writeImage($target_path)) {
                            $upload_success = true;
                        } else {
                            $upload_message = "🔴 Error: Imagick failed to write the cleaned PNG file.";
                        }
                        $image->clear();
                        $image->destroy();
                    } catch (\ImagickException $e) {
                        $upload_message = "🔴 Imagick Error during PNG processing: " . $e->getMessage();
                    }
                }
                
                // --- ZIP or PNG Fallback ---
                else {
                    // Use standard move for ZIPs, or if Imagick is not available for PNGs
                    if (move_uploaded_file($file['tmp_name'], $target_path)) {
                        $upload_success = true;
                    } else {
                        $upload_message = "🔴 Error: Failed to move uploaded file.";
                    }
                }

                if ($upload_success) {
                    // Success! Generate the public URL.
                    $uploaded_url = "https://" . $domain_prefix . "/" . $final_filename;
                    $upload_message = "✅ Success! Your file is available at: <a href=\"$uploaded_url\">$uploaded_url</a>";
                }

            } catch (\RuntimeException $e) {
                $upload_message = "🔴 Server Error: " . $e->getMessage();
            }
        }
    }
}

// --- HTML Output ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure File Uploader</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f4f9; }
        .container { max-width: 600px; }
    </style>
</head>
<body class="p-6 flex justify-center items-center min-h-screen">
    <div class="container bg-white p-8 rounded-xl shadow-2xl w-full">
        <h1 class="text-3xl font-bold text-gray-800 mb-6 border-b pb-2">Secure File Uploader</h1>

        <!-- Dependency Status Messages -->
        <?php foreach ($status_messages as $msg): ?>
            <div class="p-3 my-3 text-sm rounded-lg <?= strpos($msg, 'WARNING') !== false ? 'bg-yellow-100 text-yellow-800 border border-yellow-300' : 'bg-red-100 text-red-800 border border-red-300' ?>">
                <?= $msg ?>
            </div>
        <?php endforeach; ?>

        <!-- Upload Result Message -->
        <?php if ($upload_message): ?>
            <div class="p-4 my-4 rounded-xl font-medium <?= strpos($upload_message, '✅') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                <?= $upload_message ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <div>
                <label for="upload_file" class="block text-sm font-medium text-gray-700 mb-2">
                    Select File (.png or .zip, Max <?= $max_file_size_ini_str ?>):
                </label>
                <!-- MAX_FILE_SIZE (Client-side limit) is dynamically set in bytes -->
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= $max_file_size ?>" /> 
                <input type="file" name="upload_file" id="upload_file" required 
                       class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 p-2.5">
            </div>
            <div>
                <button type="submit" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200 shadow-md">
                    Upload File
                </button>
            </div>
        </form>

        <p class="mt-6 text-xs text-gray-500 pt-4 border-t">
            Generated URL format: <code>https://<?= $domain_prefix ?>/randomizedurl.fileextension</code><br>
            Base name uniqueness check is guaranteed across all extensions.
        </p>

    </div>
</body>
</html>
