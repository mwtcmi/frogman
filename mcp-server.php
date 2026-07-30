#!/usr/bin/env php
<?php
/**
 * Frogman MCP Server
 *
 * A Model Context Protocol (MCP) server that exposes Frogman tools
 * to Claude Desktop and other MCP clients via JSON-RPC over stdio.
 *
 * Communicates with FreePBX via the localhost ajax endpoint.
 */

// Configuration
$FREEPBX_URL = getenv('FROGMAN_FREEPBX_URL') ?: 'http://localhost/admin/ajax.php';

// Cap memory so a runaway tool/parser bug fails fast instead of consuming the box
ini_set('memory_limit', '256M');

// Disable output buffering for stdio
ini_set('output_buffering', 'off');
ini_set('implicit_flush', true);
ob_implicit_flush(true);

// Log to stderr (MCP spec: stdout = protocol, stderr = diagnostics)
function mcp_log($msg) {
    fwrite(STDERR, "[frogman-mcp] " . $msg . "\n");
}

// Send a JSON-RPC response (bare JSON line — no Content-Length framing)
function send_response($id, $result) {
    $response = [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
    ];
    $json = json_encode($response, JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, $json . "\n");
    fflush(STDOUT);
}

// Send a JSON-RPC error
function send_error($id, $code, $message) {
    $response = [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];
    $json = json_encode($response, JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, $json . "\n");
    fflush(STDOUT);
}

// Send a JSON-RPC notification (no id)
function send_notification($method, $params = null) {
    $msg = [
        'jsonrpc' => '2.0',
        'method' => $method,
    ];
    if ($params !== null) {
        $msg['params'] = $params;
    }
    $json = json_encode($msg, JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, $json . "\n");
    fflush(STDOUT);
}

// Call the FreePBX ajax endpoint
function freepbx_request($url, $command, $postData = null) {
    $fullUrl = $url . '?module=frogman&command=' . urlencode($command);
    $ch = curl_init($fullUrl);
    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => "HTTP error: {$error}"];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['error' => "Invalid JSON response (HTTP {$httpCode}): " . substr($response, 0, 200)];
    }

    return $decoded;
}

// Fetch the tool catalog and convert to MCP tool format.
// Returns ['tools' => [...]] on success or ['error' => string] on failure. Callers must
// not cache a failure — a backend that recovers should be picked up on the next request.
function get_mcp_tools($url) {
    $catalog = freepbx_request($url, 'catalog');
    if (isset($catalog['error'])) {
        return ['error' => $catalog['error']];
    }
    if (($catalog['status'] ?? '') !== 'success') {
        return ['error' => 'catalog request returned ' . json_encode($catalog)];
    }
    if (!is_array($catalog['tools'] ?? null)) {
        return ['error' => 'catalog response contained no tool list'];
    }

    $tools = [];
    foreach ($catalog['tools'] as $tool) {
        $tools[] = [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'params' => [
                        'type' => 'object',
                        'description' => 'Tool parameters as a JSON object. See tool description for available parameters.',
                    ],
                ],
            ],
        ];
    }
    return ['tools' => $tools];
}

// Execute a tool via the ajax endpoint
function execute_tool($url, $toolName, $params) {
    return freepbx_request($url, 'tool', [
        'tool' => $toolName,
        'params' => $params,
    ]);
}

// Read a JSON-RPC message from stdin (Content-Length framed or bare JSON)
function read_message() {
    $contentLength = null;
    $emptyReads = 0;

    // Read headers (or a bare JSON line)
    while (true) {
        $line = fgets(STDIN);
        if ($line === false || feof(STDIN)) {
            return null; // EOF — connection closed
        }
        $trimmed = trim($line);
        if ($trimmed === '') {
            $emptyReads++;
            // Protect against spin loop — if we get many empty reads, connection is dead
            if ($emptyReads > 10) {
                mcp_log("Too many empty reads, connection likely dead");
                return null;
            }
            if ($contentLength !== null) {
                break; // End of headers, body follows
            }
            usleep(10000); // 10ms pause to prevent CPU spin
            continue;
        }
        $emptyReads = 0;
        if (preg_match('/^Content-Length:\s*(\d+)/i', $trimmed, $matches)) {
            $contentLength = (int) $matches[1];
        } elseif ($trimmed[0] === '{') {
            // Bare JSON line (no Content-Length framing) — parse directly
            return json_decode($trimmed, true);
        }
    }

    if ($contentLength === null) {
        // Got empty line but no Content-Length — read next line as JSON
        $line = fgets(STDIN);
        if ($line === false || feof(STDIN)) {
            return null;
        }
        return json_decode(trim($line), true);
    }

    // Read body by Content-Length
    $body = '';
    $remaining = $contentLength;
    while ($remaining > 0) {
        $chunk = fread(STDIN, $remaining);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $body .= $chunk;
        $remaining -= strlen($chunk);
    }

    return json_decode($body, true);
}

// ── Main Loop ──────────────────────────────────────────────────

mcp_log("Starting Frogman MCP server");
mcp_log("FreePBX URL: {$FREEPBX_URL}");

$initialized = false;
$toolCache = null;
$lastActivity = time();
$idleTimeout = 300; // 5 minutes — shut down if no messages

// Set stdin to non-blocking so we can check for idle timeout
stream_set_timeout(STDIN, 60);

while (true) {
    // Check idle timeout
    if (time() - $lastActivity > $idleTimeout) {
        mcp_log("Idle timeout ({$idleTimeout}s), shutting down");
        break;
    }

    $message = read_message();
    if ($message === null) {
        // Could be EOF or timeout — check if stdin is actually closed
        if (feof(STDIN)) {
            mcp_log("EOF on stdin, shutting down");
            break;
        }
        // Timeout on read — loop back and check idle
        continue;
    }
    $lastActivity = time();

    $method = $message['method'] ?? '';
    $id = $message['id'] ?? null;
    $params = $message['params'] ?? [];

    mcp_log("Received: {$method}" . ($id !== null ? " (id: {$id})" : " (notification)"));

    switch ($method) {
        case 'initialize':
            $initialized = true;

            // Respond immediately — defer catalog fetch to tools/list
            send_response($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => new \stdClass(),
                ],
                'serverInfo' => [
                    'name' => 'frogman-mcp',
                    'version' => '1.0.0',
                ],
            ]);
            break;

        case 'notifications/initialized':
            mcp_log("Client confirmed initialization");
            break;

        case 'tools/list':
            if ($toolCache === null) {
                $catalog = get_mcp_tools($FREEPBX_URL);
                if (isset($catalog['error'])) {
                    // Leave $toolCache null so the next tools/list retries rather than
                    // serving an empty catalog for the life of the process.
                    mcp_log("Catalog fetch failed: {$catalog['error']}");
                    send_error($id, -32603, "Cannot reach the Frogman tool catalog at {$FREEPBX_URL} — {$catalog['error']}");
                    break;
                }
                $toolCache = $catalog['tools'];
            }
            send_response($id, [
                'tools' => $toolCache,
            ]);
            break;

        case 'tools/call':
            $toolName = $params['name'] ?? '';
            $arguments = $params['arguments'] ?? [];
            // The tool params are either in arguments.params or directly in arguments
            $toolParams = $arguments['params'] ?? $arguments;

            mcp_log("Calling tool: {$toolName} with params: " . json_encode($toolParams));

            $result = execute_tool($FREEPBX_URL, $toolName, $toolParams);

            if (isset($result['status']) && $result['status'] === 'success') {
                send_response($id, [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ]);
            } else {
                $errorMsg = $result['message'] ?? $result['error'] ?? 'Unknown error';
                send_response($id, [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode(['error' => $errorMsg], JSON_PRETTY_PRINT),
                        ],
                    ],
                    'isError' => true,
                ]);
            }
            break;

        case 'ping':
            send_response($id, new \stdClass());
            break;

        default:
            if ($id !== null) {
                send_error($id, -32601, "Method not found: {$method}");
            }
            break;
    }
}

mcp_log("MCP server shut down");
