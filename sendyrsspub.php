<?php
// sendyrsspub.php
// PHP translation of sendyrsspub.py
// Copyright (c) 2015-2018 Damien Tougas (original Python)
// License: MIT

// --- CONFIG ---
require_once 'settings.php'; // expects DEFAULTS constant/array

// --- RSS Feed Parser ---
function parse_feed($feed_url) {
    $xml = @simplexml_load_file($feed_url);
    if ($xml === false) {
        throw new Exception("Failed to load RSS feed: $feed_url");
    }
    return $xml;
}

// --- SQLite Feed Log ---
class SQLiteFeedLog {
    private $conn;
    private $feed_url;

    public function __construct($file_name, $feed_url) {
        $this->feed_url = $feed_url;
        $this->conn = new PDO('sqlite:'.$file_name);
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->conn->exec("CREATE TABLE IF NOT EXISTS feed_log (feed_url TEXT, entry_id TEXT)");
    }
    public function add($entry_id) {
        if ($this->exists($entry_id)) return;
        $stmt = $this->conn->prepare("INSERT INTO feed_log (feed_url, entry_id) VALUES (?, ?)");
        $stmt->execute([$this->feed_url, $entry_id]);
    }
    public function remove($entry_id) {
        $stmt = $this->conn->prepare("DELETE FROM feed_log WHERE feed_url=? AND entry_id=?");
        $stmt->execute([$this->feed_url, $entry_id]);
    }
    public function prune($remainder=10) {
        $stmt = $this->conn->prepare("SELECT entry_id FROM feed_log WHERE feed_url=? ORDER BY rowid DESC LIMIT -1 OFFSET ?");
        $stmt->execute([$this->feed_url, $remainder]);
        $to_remove = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($to_remove as $entry_id) {
            $this->remove($entry_id);
        }
    }
    public function clear() {
        $stmt = $this->conn->prepare("DELETE FROM feed_log WHERE feed_url=?");
        $stmt->execute([$this->feed_url]);
    }
    public function exists($entry_id) {
        $stmt = $this->conn->prepare("SELECT 1 FROM feed_log WHERE feed_url=? AND entry_id=?");
        $stmt->execute([$this->feed_url, $entry_id]);
        return $stmt->fetchColumn() !== false;
    }
    public function close() {
        $this->conn = null;
    }
}

// --- Sendy RSS Publisher ---
class SendyRSSPublisher {
    private $sendy_url;
    private $sendy_api_key;
    private $feed_url;
    private $feed_log;

    public function __construct($sendy_url, $sendy_api_key, $feed_url, $feed_log) {
        $this->sendy_url = $sendy_url;
        $this->sendy_api_key = $sendy_api_key;
        $this->feed_url = $feed_url;
        $this->feed_log = $feed_log;
    }
    public function parse_feed() {
        return parse_feed($this->feed_url);
    }
    public function log_feed_data($entries) {
        foreach ($entries as $entry) {
            $this->feed_log->add($entry->guid);
        }
    }
    public function prune_feed_data($entries) {
        $new_entries = [];
        foreach ($entries as $entry) {
            if (!$this->feed_log->exists($entry->guid)) {
                $new_entries[] = $entry;
            }
        }
        return $new_entries;
    }
    public function render_template($template_file, $data) {
        // Simple PHP template rendering
        extract($data);
        ob_start();
        include $template_file;
        return ob_get_clean();
    }
    public function render_and_send($from_name, $from_email, $reply_to, $subject_template, $plain_template, $html_template, $data, $list_ids) {
        if (empty($data['entries'])) return;
        $subject = $this->render_template($subject_template, $data);
        $plain_text = $this->render_template($plain_template, $data);
        $html_text = $this->render_template($html_template, $data);
        return $this->send($from_name, $from_email, $reply_to, $subject, $plain_text, $html_text, $list_ids);
    }
    public function send($from_name, $from_email, $reply_to, $subject, $plain_text, $html_text, $list_ids) {
        $post_data = [
            'api_key' => $this->sendy_api_key,
            'from_name' => $from_name,
            'from_email' => $from_email,
            'reply_to' => $reply_to,
            'subject' => $subject,
            'plain_text' => $plain_text,
            'html_text' => $html_text,
            'list_ids' => $list_ids,
            'send_campaign' => 1
        ];
        $url = rtrim($this->sendy_url, '/') . '/api/campaigns/create.php';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status != 200 || trim($response) != 'Campaign created and now sending') {
            throw new Exception("ERROR: Status $status: $response");
        }
    }
}

// --- Command Line Processing ---
function print_usage() {
    echo "Usage: php sendyrsspub.php <command> [options]\n";
    echo "Commands: test_feed, test_template, send_newsletter, db_clear, db_prune\n";
    exit(1);
}
public function prune($remainder=10) {
    $stmt = $this->conn->prepare(
        "SELECT entry_id FROM feed_log WHERE feed_url=? ORDER BY rowid ASC"
    );
    $stmt->execute([$this->feed_url]);
    $all = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $to_remove = array_slice($all, 0, max(0, count($all) - $remainder));
    foreach ($to_remove as $entry_id) {
        $this->remove($entry_id);
    }
}
function main($argv) {
    global $DEFAULTS;
    if (count($argv) < 2) print_usage();
    $cmd = $argv[1];
    $opts = getopt("f:t:n:e:r:s:l:a::d::", [
        "feed-url:", "template:", "from-name:", "from-email:", "reply-to:", "subject:", "list-ids:", "all::", "disable-log::"
    ]);
    $feed_url = $opts['f'] ?? $opts['feed-url'] ?? $DEFAULTS['feed_url'];
    $template = $opts['t'] ?? $opts['template'] ?? $DEFAULTS['template'];
    $from_name = $opts['n'] ?? $opts['from-name'] ?? $DEFAULTS['from_name'];
    $from_email = $opts['e'] ?? $opts['from-email'] ?? $DEFAULTS['from_email'];
    $reply_to = $opts['r'] ?? $opts['reply-to'] ?? $DEFAULTS['reply_to'];
    $subject = $opts['s'] ?? $opts['subject'] ?? $DEFAULTS['subject'];
    $list_ids = $opts['l'] ?? $opts['list-ids'] ?? $DEFAULTS['list_ids'];
    $feed_log = new SQLiteFeedLog('feedlog.sqlite', $feed_url);
    $publisher = new SendyRSSPublisher($DEFAULTS['sendy_url'], $DEFAULTS['sendy_api_key'], $feed_url, $feed_log);

    switch ($cmd) {
        case 'test_feed':
            $feed = $publisher->parse_feed();
            print_r($feed);
            break;
        case 'test_template':
            $feed = $publisher->parse_feed();
            $entries = $feed->channel->item ?? [];
            $data = ['entries' => $entries];
            $output = $publisher->render_template($template, $data);
            echo $output;
            break;
        case 'send_newsletter':
            $feed = $publisher->parse_feed();
            $entries = $feed->channel->item ?? [];
            $data = ['entries' => $publisher->prune_feed_data($entries)];
            $publisher->render_and_send($from_name, $from_email, $reply_to, $template, $template, $template, $data, $list_ids);
            $publisher->log_feed_data($entries);
            break;
        case 'db_clear':
            $feed_log->clear();
            echo "Feed log cleared.\n";
            break;
        case 'db_prune':
            $feed_log->prune();
            echo "Feed log pruned.\n";
            break;
        default:
            print_usage();
    }
}

if (php_sapi_name() == 'cli') {
    main($argv);
}
