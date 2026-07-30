<?php
/**
 * Shoe Multi-Agent AI - Backend API Controller
 * Built-in PHP server router or direct script runner
 * Vercel-compatible: reads API key from environment variable GEMINI_API_KEY
 */

// Cors headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// On Vercel, filesystem is read-only except /tmp.
// Use /tmp for writable data, fall back to __DIR__ for local dev.
$isVercel = isset($_SERVER['VERCEL']) || getenv('VERCEL');
$dataDir  = $isVercel ? '/tmp/shoes_data' : dirname(__DIR__) . '/data';

// Ensure data directory exists
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// On Vercel first boot, copy seed files from static /data into /tmp so we have initial products
$staticDataDir = dirname(__DIR__) . '/data';
foreach (['products.json', 'chats.json'] as $seedFile) {
    $tmpPath    = $dataDir . '/' . $seedFile;
    $staticPath = $staticDataDir . '/' . $seedFile;
    if (!file_exists($tmpPath) && file_exists($staticPath)) {
        copy($staticPath, $tmpPath);
    }
}

$productsFile = $dataDir . '/products.json';
$chatsFile    = $dataDir . '/chats.json';
$settingsFile = $dataDir . '/settings.json';

// Read Gemini API Key: prefer environment variable (set in Vercel dashboard),
// then fall back to settings file for local dev.
$envApiKey = getenv('GEMINI_API_KEY') ?: '';

// Helper to read JSON database
function readDb($file, $default = []) {
    if (!file_exists($file)) {
        return $default;
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

// Helper to write JSON database
function writeDb($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Helper to call Google Gemini API
function callGemini($apiKey, $systemPrompt, $userPrompt, $chatHistory = []) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
    
    // Map history to Gemini format (user/model roles)
    $contents = [];
    
    // Take last 8 messages of history to avoid payload bloat
    $historySlice = array_slice($chatHistory, -8);
    foreach ($historySlice as $msg) {
        $role = $msg['sender'] === 'user' ? 'user' : 'model';
        $contents[] = [
            'role' => $role,
            'parts' => [['text' => $msg['text']]]
        ];
    }
    
    // Add current user prompt
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $userPrompt]]
    ];

    $payload = [
        'contents' => $contents,
        'systemInstruction' => [
            'parts' => [['text' => $systemPrompt]]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 1000
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Local developer compatibility
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return false; // Indicate failure to fall back
    }

    $resData = json_decode($response, true);
    if (isset($resData['candidates'][0]['content']['parts'][0]['text'])) {
        return $resData['candidates'][0]['content']['parts'][0]['text'];
    }

    return false;
}

// Extract path
$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Remove prefix: /api, /api.php, or /api/index.php
if (strpos($requestPath, '/api/index.php') === 0) {
    $path = substr($requestPath, strlen('/api/index.php'));
} elseif (strpos($requestPath, '/api.php') === 0) {
    $path = substr($requestPath, strlen('/api.php'));
} elseif (strpos($requestPath, '/api') === 0) {
    $path = substr($requestPath, strlen('/api'));
} else {
    $path = $requestPath;
}

$path = rtrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Parse input body
$input = [];
if ($method === 'POST') {
    $body = file_get_contents('php://input');
    if (!empty($body)) {
        $input = json_decode($body, true) ?? [];
    }
}

// Router
switch ($path) {
    case '/settings':
        if ($method === 'GET') {
            $settings = readDb($settingsFile, [
                'darkMode' => true,
                'notifications' => false,
                'chatVoice' => true,
                'language' => 'Indonesia',
                'geminiApiKey' => '' // Set your Gemini API key via the Settings page
            ]);
            echo json_encode($settings);
        } elseif ($method === 'POST') {
            $settings = readDb($settingsFile, [
                'darkMode' => true,
                'notifications' => false,
                'chatVoice' => true,
                'language' => 'Indonesia',
                'geminiApiKey' => '' // Set your Gemini API key via the Settings page
            ]);
            $updated = array_merge($settings, $input);
            writeDb($settingsFile, $updated);
            echo json_encode(['status' => 'success', 'data' => $updated]);
        }
        break;

    case '/settings/clear-chats':
        if ($method === 'POST') {
            // Reset to default chat
            $defaultChat = [
                [
                    'id' => 'session_default',
                    'title' => 'Perbedaan Grade A & B',
                    'createdAt' => date('c'),
                    'messages' => [
                        [
                            'id' => 'm1',
                            'sender' => 'ai',
                            'text' => 'Ada yang bisa saya bantu?',
                            'timestamp' => date('g:i A'),
                            'agent' => null
                        ]
                    ]
                ]
            ];
            writeDb($chatsFile, $defaultChat);
            echo json_encode(['status' => 'success', 'message' => 'History cleared']);
        }
        break;

    case '/products':
        if ($method === 'GET') {
            $products = readDb($productsFile, []);
            echo json_encode($products);
        } elseif ($method === 'POST') {
            $products = readDb($productsFile, []);
            $newProduct = [
                'id' => count($products) > 0 ? max(array_column($products, 'id')) + 1 : 1,
                'name' => $input['name'] ?? 'Unnamed Shoe',
                'brand' => $input['brand'] ?? 'Unknown',
                'price' => (int)($input['price'] ?? 0),
                'grade' => $input['grade'] ?? 'Grade A',
                'emoji' => $input['emoji'] ?? '👟',
                'gradient' => $input['gradient'] ?? 'linear-gradient(135deg, #3b82f6, #8b5cf6)'
            ];
            $products[] = $newProduct;
            writeDb($productsFile, $products);
            echo json_encode(['status' => 'success', 'data' => $newProduct]);
        }
        break;

    case '/products/import':
        if ($method === 'POST') {
            if (!is_array($input) || empty($input)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid product list']);
                break;
            }

            // Expects array of product objects
            $products = [];
            $gradients = [
                'linear-gradient(135deg, #3b82f6, #8b5cf6)',
                'linear-gradient(135deg, #10b981, #3b82f6)',
                'linear-gradient(135deg, #f59e0b, #ef4444)',
                'linear-gradient(135deg, #ec4899, #8b5cf6)',
                'linear-gradient(135deg, #06b6d4, #3b82f6)',
                'linear-gradient(135deg, #10b981, #059669)',
                'linear-gradient(135deg, #ef4444, #f59e0b)',
                'linear-gradient(135deg, #8b5cf6, #ec4899)'
            ];
            $emojis = ['👟', '👞', '🏃', '🛹', '🥾'];

            foreach ($input as $index => $item) {
                // Normalize keys to lowercase for lenient matching
                $normalizedItem = [];
                foreach ($item as $k => $v) {
                    $normalizedItem[strtolower(trim($k))] = $v;
                }

                $name = $normalizedItem['name'] ?? $normalizedItem['nama'] ?? $normalizedItem['nama sepatu'] ?? $normalizedItem['sepatu'] ?? $normalizedItem['product'] ?? '';
                
                // Fallback: If no known name column, try to find ANY column that looks like a product name
                if (empty($name) && !empty($normalizedItem)) {
                    foreach ($normalizedItem as $val) {
                        if (is_string($val) && strlen(trim($val)) > 2 && !is_numeric($val)) {
                            $name = trim($val);
                            break;
                        }
                    }
                }

                if (empty($name)) continue;

                $brand = $normalizedItem['brand'] ?? $normalizedItem['merk'] ?? $normalizedItem['merek'] ?? 'Unknown';
                $priceVal = $normalizedItem['price'] ?? $normalizedItem['harga'] ?? 0;
                $grade = $normalizedItem['grade'] ?? $normalizedItem['kualitas'] ?? $normalizedItem['kualiti'] ?? 'Grade A';

                // Ultra-smart fallback for price: find any large number in the row
                if (empty($priceVal) || $priceVal === 0) {
                    foreach ($normalizedItem as $val) {
                        $num = (int)preg_replace('/[^0-9]/', '', (string)$val);
                        if ($num > 10000) { // likely a price in Rupiah
                            $priceVal = $num;
                            break;
                        }
                    }
                }

                // Parse integer from price
                $price = (int)preg_replace('/[^0-9]/', '', (string)$priceVal);

                // Ultra-smart fallback for brand
                if ($brand === 'Unknown') {
                    $lowerName = strtolower($name);
                    if (strpos($lowerName, 'nike') !== false || strpos($lowerName, 'jordan') !== false || strpos($lowerName, 'air force') !== false) $brand = 'Nike';
                    elseif (strpos($lowerName, 'adidas') !== false || strpos($lowerName, 'yeezy') !== false || strpos($lowerName, 'smith') !== false || strpos($lowerName, 'ultraboost') !== false) $brand = 'Adidas';
                    elseif (strpos($lowerName, 'vans') !== false || strpos($lowerName, 'authentic') !== false || strpos($lowerName, 'sk8') !== false || strpos($lowerName, 'os') !== false || strpos($lowerName, 'slip on') !== false) $brand = 'Vans';
                    elseif (strpos($lowerName, 'converse') !== false || strpos($lowerName, 'chuck') !== false || strpos($lowerName, 'ct') !== false) $brand = 'Converse';
                    elseif (strpos($lowerName, 'puma') !== false) $brand = 'Puma';
                    elseif (strpos($lowerName, 'new balance') !== false || strpos($lowerName, 'nb ') !== false) $brand = 'NB';
                    else {
                        foreach ($normalizedItem as $val) {
                            if (is_string($val) && strlen(trim($val)) > 2 && trim($val) !== $name && !is_numeric($val) && strpos(strtolower($val), 'grade') === false) {
                                $brand = trim($val);
                                break;
                            }
                        }
                    }
                }

                // Fallback for grade
                if ($grade === 'Grade A') {
                    foreach ($normalizedItem as $val) {
                        if (is_string($val) && preg_match('/(grade b|premium|ori|kw)/i', $val, $matches)) {
                            $grade = $matches[0];
                            break;
                        }
                    }
                }
                
                // Keep "Grade A" or "Grade B" format
                if (stripos($grade, 'A') !== false) {
                    $grade = 'Grade A';
                } else {
                    $grade = 'Grade B';
                }

                $emoji = $normalizedItem['emoji'] ?? $emojis[$index % count($emojis)];
                $gradient = $normalizedItem['gradient'] ?? $gradients[$index % count($gradients)];

                $products[] = [
                    'id' => $index + 1,
                    'name' => $name,
                    'brand' => $brand,
                    'price' => $price,
                    'grade' => $grade,
                    'emoji' => $emoji,
                    'gradient' => $gradient
                ];
            }

            if (empty($products)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'No valid products found in import']);
                break;
            }

            // Read existing products to append instead of overwrite
            $existingProducts = readDb($productsFile, []);
            $maxId = 0;
            foreach ($existingProducts as $ep) {
                if ($ep['id'] > $maxId) {
                    $maxId = $ep['id'];
                }
            }
            
            // Assign new IDs and append
            foreach ($products as &$p) {
                $maxId++;
                $p['id'] = $maxId;
                $existingProducts[] = $p;
            }

            writeDb($productsFile, $existingProducts);
            echo json_encode(['status' => 'success', 'message' => count($products) . ' products imported and appended successfully', 'data' => $existingProducts]);
        }
        break;

    case '/agents':
        if ($method === 'GET') {
            $agents = [
                [
                    'name' => 'Grade Analyzer Agent',
                    'icon' => 'A',
                    'color' => '#10b981',
                    'description' => 'Menganalisis dan membandingkan kualitas grade sepatu secara mendetail.',
                    'status' => 'Aktif'
                ],
                [
                    'name' => 'Size Recommender Agent',
                    'icon' => '📐',
                    'color' => '#f97316',
                    'description' => 'Merekomendasikan ukuran sepatu terbaik berdasarkan preferensi Anda.',
                    'status' => 'Aktif'
                ],
                [
                    'name' => 'Style Advisor Agent',
                    'icon' => '✨',
                    'color' => '#8b5cf6',
                    'description' => 'Memberikan saran gaya dan kombinasi outfit dengan sepatu.',
                    'status' => 'Aktif'
                ],
                [
                    'name' => 'Stock Checker Agent',
                    'icon' => '📦',
                    'color' => '#d97706',
                    'description' => 'Memeriksa ketersediaan stok produk secara real-time.',
                    'status' => 'Aktif'
                ]
            ];
            echo json_encode($agents);
        }
        break;

    case '/chats':
        if ($method === 'GET') {
            $chats = readDb($chatsFile, []);
            echo json_encode($chats);
        } elseif ($method === 'POST') {
            $chats = readDb($chatsFile, []);
            $newSessionId = 'session_' . uniqid();
            $title = $input['title'] ?? 'Obrolan Baru';
            $newChat = [
                'id' => $newSessionId,
                'title' => $title,
                'createdAt' => date('c'),
                'messages' => [
                    [
                        'id' => 'm_' . uniqid(),
                        'sender' => 'ai',
                        'text' => 'Ada yang bisa saya bantu?',
                        'timestamp' => date('g:i A'),
                        'agent' => null
                    ]
                ]
            ];
            $chats[] = $newChat;
            writeDb($chatsFile, $chats);
            echo json_encode($newChat);
        }
        break;

    case '/chats/message':
        if ($method === 'POST') {
            $sessionId = $input['sessionId'] ?? '';
            $text = $input['text'] ?? '';

            if (empty($sessionId) || empty($text)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Session ID and text are required']);
                break;
            }

            $chats = readDb($chatsFile, []);
            $sessionIndex = -1;
            for ($i = 0; $i < count($chats); $i++) {
                if ($chats[$i]['id'] === $sessionId) {
                    $sessionIndex = $i;
                    break;
                }
            }

            if ($sessionIndex === -1) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Chat session not found']);
                break;
            }

            // Fetch API key: environment variable takes priority (Vercel),
            // then fall back to settings file (local dev / Settings page input).
            $settings = readDb($settingsFile, [
                'darkMode' => true,
                'notifications' => false,
                'chatVoice' => true,
                'language' => 'Indonesia',
                'geminiApiKey' => ''
            ]);
            $geminiApiKey = !empty($envApiKey) ? $envApiKey : ($settings['geminiApiKey'] ?? '');

            // Append user message
            $userMsg = [
                'id' => 'm_' . uniqid(),
                'sender' => 'user',
                'text' => $text,
                'timestamp' => date('g:i A'),
                'agent' => null
            ];
            $chats[$sessionIndex]['messages'][] = $userMsg;

            // Route to appropriate Agent
            $activeAgent = null;
            $textLower = strtolower($text);

            if (preg_match('/(grade|perbedaan|kualitas|kw|ori|original|palsu|asli)/i', $textLower)) {
                $activeAgent = 'Grade Analyzer Agent';
            } elseif (preg_match('/(ukuran|size|cm|senti|kaki|panjang kaki|fit)/i', $textLower)) {
                $activeAgent = 'Size Recommender Agent';
            } elseif (preg_match('/(stok|stock|beli|ready|ada|habis|harga|price|cari)/i', $textLower)) {
                $activeAgent = 'Stock Checker Agent';
            } elseif (preg_match('/(style|gaya|outfit|pakaian|cocok|kombinasi|baju|celana)/i', $textLower)) {
                $activeAgent = 'Style Advisor Agent';
            }

            // Responses Initialization
            $responseText = false;

            // IF Gemini API Key is configured, attempt call
            if (!empty($geminiApiKey)) {
                $systemPrompt = "";
                
                switch ($activeAgent) {
                    case 'Grade Analyzer Agent':
                        $systemPrompt = "Anda adalah Grade Analyzer Agent untuk Shoe Multi-Agent AI (asisten konsultasi sepatu). Tugas Anda adalah menganalisis dan membandingkan kualitas grade sepatu secara mendetail (Grade A vs Grade B).\n\n" .
                                        "Pedoman respons Anda:\n" .
                                        "- Jika pengguna menanyakan tentang perbedaan Grade A dan Grade B pada Nike Air Jordan, Anda harus menampilkan tabel berikut secara persis:\n\n" .
                                        "| Aspek | Grade A | Grade B |\n" .
                                        "| --- | --- | --- |\n" .
                                        "| **Kualitas Material** | Premium, 100% original | Standar, minor defect |\n" .
                                        "| **Jahitan** | Rapi dan presisi | Sedikit tidak rapi |\n" .
                                        "| **Kenyamanan** | Sangat nyaman | Cukup nyaman |\n" .
                                        "| **Detail** | Sempurna | Ada sedikit perbedaan |\n" .
                                        "| **Harga** | Lebih tinggi | Lebih terjangkau |\n\n" .
                                        "Grade A cocok untuk kolektor atau penggunaan jangka panjang, sedangkan Grade B cocok untuk penggunaan sehari-hari dengan harga lebih ekonomis.\n\n" .
                                        "- Untuk model sepatu lain, berikan penjelasan terstruktur (misal dalam bentuk tabel atau poin-poin) yang membandingkan material, jahitan, sol, tag, box, dan harga antara Grade A (Original/Premium) dengan Grade B.\n" .
                                        "- Jawablah menggunakan bahasa Indonesia yang ramah, profesional, dan informatif.";
                        break;

                    case 'Size Recommender Agent':
                        $systemPrompt = "Anda adalah Size Recommender Agent untuk Shoe Multi-Agent AI. Tugas Anda adalah merekomendasikan ukuran sepatu terbaik berdasarkan preferensi pengguna.\n\n" .
                                        "Pedoman respons Anda:\n" .
                                        "- Jika pengguna memberikan panjang kakinya dalam cm, hitung dan berikan rekomendasi ukuran EU, US, dan UK. Gunakan konversi standar:\n" .
                                        "  * < 23 cm: EU 36-37, US 4.5-5\n" .
                                        "  * 23-24 cm: EU 37.5-38, US 5.5-6\n" .
                                        "  * 24-25 cm: EU 38.5-39, US 6.5-7\n" .
                                        "  * 25-26 cm: EU 40-41, US 7.5-8\n" .
                                        "  * 26-27 cm: EU 42-42.5, US 8.5-9\n" .
                                        "  * 27-28 cm: EU 43-44, US 9.5-10\n" .
                                        "  * > 28 cm: EU 44.5-45+, US 10.5-11+\n" .
                                        "- Ingatkan bahwa merk yang berbeda memiliki kecocokan yang sedikit berbeda (misal Nike true-to-size, Adidas disarankan up-size 0.5).\n" .
                                        "- Jika pengguna tidak memberikan panjang kaki, berikan panduan cara mengukur kaki menggunakan kertas dan penggaris, lalu minta mereka menginfokan panjang kakinya.\n" .
                                        "- Jawablah menggunakan bahasa Indonesia yang ramah dan jelas.";
                        break;

                    case 'Stock Checker Agent':
                        $productsList = readDb($productsFile, []);
                        $productsContext = json_encode($productsList, JSON_PRETTY_PRINT);
                        $systemPrompt = "Anda adalah Stock Checker Agent untuk Shoe Multi-Agent AI. Tugas Anda adalah memeriksa ketersediaan stok produk dan memberikan informasi harga/grade.\n\n" .
                                        "Katalog produk aktif saat ini:\n" . $productsContext . "\n\n" .
                                        "Pedoman respons Anda:\n" .
                                        "- Analisis pertanyaan pengguna dan cari kecocokan dengan katalog produk aktif di atas.\n" .
                                        "- Informasikan ketersediaan produk, merk, harga (tampilkan dalam format rupiah, misal Rp 1.850.000), dan grade-nya.\n" .
                                        "- Status stok bisa disimulasikan secara dinamis dan menarik (misal 'Tersedia', 'Hanya sisa 2 pasang!', atau 'Habis terjual').\n" .
                                        "- Jika produk tidak ditemukan di katalog, tunjukkan beberapa produk terpopuler dari katalog di atas dan tawarkan bantuan.\n" .
                                        "- Jawablah menggunakan bahasa Indonesia yang informatif, antusias, dan ramah.";
                        break;

                    case 'Style Advisor Agent':
                        $systemPrompt = "Anda adalah Style Advisor Agent untuk Shoe Multi-Agent AI. Tugas Anda adalah memberikan saran gaya (fashion styling) dan kombinasi outfit dengan sepatu.\n\n" .
                                        "Pedoman respons Anda:\n" .
                                        "- Berikan saran pakaian (atasan, bawahan, outer, aksesoris) dan koordinasi warna yang cocok dengan model sepatu yang ditanyakan.\n" .
                                        "- Berikan tips gaya berpakaian seperti Streetwear, Casual Smart, Athleisure, atau Formal sesuai jenis sepatu.\n" .
                                        "- Jawablah menggunakan bahasa Indonesia yang komunikatif, fashionable, dan ramah.";
                        break;

                    default:
                        $systemPrompt = "Anda adalah Shoe Multi-Agent AI (asisten konsultasi sepatu). Anda bertindak sebagai Router Agent utama.\n\n" .
                                        "Tugas Anda:\n" .
                                        "- Jawab sapaan pengguna dengan ramah.\n" .
                                        "- Jelaskan secara singkat bahwa Anda memiliki 4 sub-agent ahli yang siap membantu:\n" .
                                        "  1. Grade Analyzer Agent: Menganalisis perbedaan Grade A & B.\n" .
                                        "  2. Size Recommender Agent: Memberikan rekomendasi ukuran berdasarkan panjang kaki (cm).\n" .
                                        "  3. Style Advisor Agent: Memberikan saran outfit/padu padan gaya dengan sepatu.\n" .
                                        "  4. Stock Checker Agent: Memeriksa harga, grade, dan stok sepatu di katalog.\n" .
                                        "- Undang pengguna untuk mengajukan pertanyaan mengenai salah satu topik di atas agar Anda dapat mengarahkan mereka ke agent ahli yang sesuai.\n" .
                                        "- Jawablah menggunakan bahasa Indonesia yang sopan, ramah, dan profesional.";
                        break;
                }

                // Pass the chat session's prior messages (excluding the new user prompt itself) to callGemini
                $chatHistory = array_slice($chats[$sessionIndex]['messages'], 0, -1);
                
                // Invoke API call
                $responseText = callGemini($geminiApiKey, $systemPrompt, $text, $chatHistory);
            }

            // Fallback to local rules if cURL fails or API Key is empty
            if ($responseText === false) {
                if ($activeAgent === 'Grade Analyzer Agent') {
                    if (preg_match('/(jordan|nike air jordan|nike jordan|air jordan)/i', $textLower)) {
                        $responseText = "Berikut perbedaan Grade A dan Grade B pada Nike Air Jordan:\n\n" .
                                        "| Aspek | Grade A | Grade B |\n" .
                                        "| --- | --- | --- |\n" .
                                        "| **Kualitas Material** | Premium, 100% original | Standar, minor defect |\n" .
                                        "| **Jahitan** | Rapi dan presisi | Sedikit tidak rapi |\n" .
                                        "| **Kenyamanan** | Sangat nyaman | Cukup nyaman |\n" .
                                        "| **Detail** | Sempurna | Ada sedikit perbedaan |\n" .
                                        "| **Harga** | Lebih tinggi | Lebih terjangkau |\n\n" .
                                        "Grade A cocok untuk kolektor atau penggunaan jangka panjang, sedangkan Grade B cocok untuk penggunaan sehari-hari dengan harga lebih ekonomis.";
                    } else {
                        $responseText = "Berikut adalah panduan umum perbedaan sepatu **Grade A** dan **Grade B**:\n\n" .
                                        "| Fitur / Aspek | Grade A (Premium/Original) | Grade B (Standard/Minor Defect) |\n" .
                                        "| --- | --- | --- |\n" .
                                        "| **Material** | Kulit/Suede kualitas tinggi, tanpa cacat | Sisa potongan pabrik atau ada defect tipis |\n" .
                                        "| **Sol & Lem** | Rapi, sangat kuat, tanpa bekas lem | Kadang terlihat bercak lem tipis di pinggir |\n" .
                                        "| **Kelengkapan** | Box asli, tag lengkap, tali cadangan | Box polos/pengganti, tag minimal |\n" .
                                        "| **Daya Tahan** | 2-5 tahun dengan pemakaian normal | 1-2 tahun dengan pemakaian normal |\n\n" .
                                        "Grade A diproduksi dengan standar kontrol kualitas (QC) yang ketat, sedangkan Grade B biasanya adalah produk yang tidak lolos QC ketat karena cacat kosmetik kecil (tidak mengganggu fungsi).";
                    }
                } elseif ($activeAgent === 'Size Recommender Agent') {
                    preg_match('/(\d+(\.\d+)?)\s*(cm|senti)/i', $textLower, $matches);
                    if (isset($matches[1])) {
                        $cm = floatval($matches[1]);
                        if ($cm < 23) {
                            $eu = "36 - 37"; $us = "4.5 - 5";
                        } elseif ($cm >= 23 && $cm < 24) {
                            $eu = "37.5 - 38"; $us = "5.5 - 6";
                        } elseif ($cm >= 24 && $cm < 25) {
                            $eu = "38.5 - 39"; $us = "6.5 - 7";
                        } elseif ($cm >= 25 && $cm < 26) {
                            $eu = "40 - 41"; $us = "7.5 - 8";
                        } elseif ($cm >= 26 && $cm < 27) {
                            $eu = "42 - 42.5"; $us = "8.5 - 9";
                        } elseif ($cm >= 27 && $cm < 28) {
                            $eu = "43 - 44"; $us = "9.5 - 10";
                        } else {
                            $eu = "44.5 - 45+"; $us = "10.5 - 11+";
                        }
                        $responseText = "Berdasarkan panjang kaki Anda (**$cm cm**), berikut adalah rekomendasi ukuran sepatu Anda:\n\n" .
                                        "- **Ukuran EU**: $eu\n" .
                                        "- **Ukuran US**: $us\n\n" .
                                        "*Catatan*: Tiap merk memiliki kecocokan yang sedikit berbeda (seperti Nike cenderung true to size, sedangkan Adidas terkadang disarankan up-size 0.5 untuk kenyamanan maksimal).";
                    } else {
                        $responseText = "Untuk merekomendasikan ukuran sepatu yang tepat, bolehkah saya tahu **berapa panjang kaki Anda dalam centimeter (cm)**?\n\n" .
                                        "Cara mengukurnya:\n" .
                                        "1. Letakkan kaki Anda di atas selembar kertas.\n" .
                                        "2. Gambar garis dari ujung tumit hingga ujung jari kaki terpanjang.\n" .
                                        "3. Ukur jarak garis tersebut menggunakan penggaris.";
                    }
                } elseif ($activeAgent === 'Stock Checker Agent') {
                    $products = readDb($productsFile, []);
                    $found = [];
                    foreach ($products as $p) {
                        $pNameLower = strtolower($p['name']);
                        $pBrandLower = strtolower($p['brand']);
                        
                        $isMatch = false;
                        
                        // 1. Match by Brand
                        if (!empty($pBrandLower) && $pBrandLower !== 'unknown' && strpos($textLower, $pBrandLower) !== false) {
                            $isMatch = true;
                        }
                        
                        // 2. Match by exact Name
                        if (strpos($textLower, $pNameLower) !== false) {
                            $isMatch = true;
                        }
                        
                        // 3. Match by Name words (e.g. if name is "Nike Air Max", checking if "air max" or "nike" is in query)
                        if (!$isMatch) {
                            $words = explode(' ', $pNameLower);
                            foreach ($words as $w) {
                                if (strlen($w) > 3 && strpos($textLower, $w) !== false) {
                                    $isMatch = true;
                                    break;
                                }
                            }
                        }
                        
                        if ($isMatch) {
                            // Ensure we don't add duplicates
                            $alreadyAdded = false;
                            foreach ($found as $f) {
                                if ($f['id'] === $p['id']) $alreadyAdded = true;
                            }
                            if (!$alreadyAdded) {
                                $found[] = $p;
                            }
                        }
                    }
                    if (!empty($found)) {
                        $responseText = "Saya menemukan kecocokan di katalog produk untuk pertanyaan Anda:\n\n";
                        foreach ($found as $p) {
                            $stockVal = (rand(0, 10) > 2) ? "Tersedia" : "Hanya sisa 2 pasang!";
                            $responseText .= "👟 **{$p['name']}** ({$p['brand']})\n" .
                                             "- Kualitas: **{$p['grade']}**\n" .
                                             "- Harga: **Rp " . number_format($p['price'], 0, ',', '.') . "**\n" .
                                             "- Status Stok: **$stockVal**\n\n";
                        }
                        $responseText .= "Apakah Anda ingin memesan atau menanyakan detail ukuran untuk sepatu di atas?";
                    } else {
                        $responseText = "Berikut adalah daftar produk sepatu terpopuler di katalog kami:\n\n";
                        $count = 0;
                        foreach ($products as $p) {
                            if ($count++ >= 4) break;
                            $responseText .= "- **{$p['name']}** ({$p['grade']}) - Rp " . number_format($p['price'], 0, ',', '.') . "\n";
                        }
                        $responseText .= "\nSemua produk di atas berstatus **Ready Stock**. Silakan tanyakan ketersediaan produk spesifik yang Anda inginkan!";
                    }
                } elseif ($activeAgent === 'Style Advisor Agent') {
                    if (preg_match('/(jordan|nike)/i', $textLower)) {
                        $responseText = "Untuk **Nike Air Jordan 1**, gaya berpakaian yang sangat cocok adalah **Streetwear** atau **Sporty Casual**:\n\n" .
                                        "- **Celana**: Cargo pants (tapered/loose), Ripped Jeans hitam, atau Sweatpants.\n" .
                                        "- **Atasan**: Oversized T-Shirt, Hoodie dengan grafis kontras, atau Flannel outer.\n" .
                                        "- **Warna**: Padukan pakaian warna netral (hitam, putih, abu-abu) agar warna Air Jordan Anda menjadi fokus utama (pop-out).\n\n" .
                                        "Gaya ini memberikan kesan bold, dinamis, dan sangat trendi!";
                    } elseif (preg_match('/(stan smith|adidas|ultraboost)/i', $textLower)) {
                        $responseText = "Untuk **Adidas Stan Smith** (gaya klasik minimalis) atau **Ultraboost** (sporty runner):\n\n" .
                                        "- **Stan Smith (Casual Smart)**: Sangat cocok dengan Celana Chino (krem/navy), celana bahan ankle-cut, kaos polos dimasukkan, atau blazer santai. Memberikan kesan bersih dan rapi.\n" .
                                        "- **Ultraboost (Athleisure)**: Padukan dengan Jogger pants, shorts olahraga, hoodie, atau t-shirt berpori. Sangat nyaman untuk mobilitas tinggi dan terlihat sporty modern.";
                    } else {
                        $responseText = "Halo! Sebagai penasihat gaya, berikut tips umum kombinasi sepatu:\n\n" .
                                        "1. **High-top Sneakers** (seperti Converse Chuck 70 atau Jordan High): Sangat pas dipadukan dengan celana pendek atau celana panjang yang dilipat (cuffed) agar siluet sepatu terlihat jelas.\n" .
                                        "2. **Low-top Minimalis** (Stan Smith, Vans): Fleksibel untuk segala suasana. Bisa dipakai semi-formal dengan celana chino atau casual dengan celana jeans santai.\n" .
                                        "3. **Warna Sepatu**: Jika sepatu Anda berwarna cerah, pakailah outfit dengan warna kalem/monokrom agar tidak terlalu ramai.\n\n" .
                                        "Ada model sepatu tertentu yang ingin Anda padukan dengan outfit Anda?";
                    }
                } else {
                    // Set routing to null (General) since no keyword matches
                    $activeAgent = null;
                    $responseText = "Halo! Saya adalah **Shoe Multi-Agent AI**, asisten konsultasi sepatu Anda. Saya memiliki 4 sub-agent ahli yang siap membantu:\n\n" .
                                    "1. **Grade Analyzer Agent**: Tanyakan perbedaan Grade A & B atau detail kualitas sepatu.\n" .
                                    "2. **Size Recommender Agent**: Tanyakan rekomendasi ukuran berdasarkan panjang kaki (cm).\n" .
                                    "3. **Style Advisor Agent**: Mintalah saran padu padan pakaian/outfit dengan sepatu Anda.\n" .
                                    "4. **Stock Checker Agent**: Tanyakan stok dan harga sepatu di katalog kami.\n\n" .
                                    "Silakan ajukan pertanyaan Anda mengenai sepatu secara spesifik agar saya dapat mengarahkan Anda ke agent yang tepat!";
                }
            }

            // Append AI response
            $aiMsg = [
                'id' => 'm_' . uniqid(),
                'sender' => 'ai',
                'text' => $responseText,
                'timestamp' => date('g:i A'),
                'agent' => $activeAgent
            ];
            $chats[$sessionIndex]['messages'][] = $aiMsg;

            // Update title if it was default and user asked a question
            if ($chats[$sessionIndex]['title'] === 'Obrolan Baru' && count($chats[$sessionIndex]['messages']) <= 3) {
                $title = mb_strimwidth($text, 0, 30, '...');
                $chats[$sessionIndex]['title'] = $title;
            }

            writeDb($chatsFile, $chats);
            echo json_encode([
                'status' => 'success',
                'chat' => $chats[$sessionIndex],
                'message' => $aiMsg
            ]);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'API route not found: ' . $path]);
        break;
}
