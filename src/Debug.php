<?php
namespace JackDTaylor;

class Debug {
	const MAX_LENGTH = 3000;

	static $token = null;

	// TODO: Add documentation
	static $channel = null;

	public static function configure($token, $channel) {
		static::$token = $token;
		static::$channel = $channel;
	}

	protected static function getSource($offset = 2) {
		// TODO: Improve tracing
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $offset + 1);

		$class = pos(array_reverse(explode('\\', $trace[$offset]['class'])));
		$method = $trace[$offset]['function'];
		$file = $trace[$offset - 1]['file'];
		$line = $trace[$offset - 1]['line'];

		return "<sftp:/{$file}:{$line}|{$class}@{$method}>";
	}

	public static function log(...$data) {
		return static::send(static::getSource(), 'log', $data);
	}

	public static function error(...$data) {
		return static::send(static::getSource(), 'log', $data);
	}

	protected static function send($source, $type, array $data) {
		$header = "> *{$source} {$type}*";

		$values = array_map(function($variable) {
			if(is_bool($variable)) {
				return $variable ? '_[TRUE]_' : '_[FALSE]_';
			}

			if(is_null($variable)) {
				return '_[NULL]_';
			}

			$lines = explode(PHP_EOL, print_r($variable, true));
			foreach($lines as &$line) {
				if(mb_strlen($line) > static::MAX_LENGTH) {
					$line = chunk_split($line, static::MAX_LENGTH);
				}
			}

			return '```' . implode(PHP_EOL, $lines) . '```';
		}, $data);
		$values = implode(PHP_EOL.PHP_EOL, $values);

		$chunks = [];
		$chunk = [];
		$lines = explode(PHP_EOL, $values);
		$cumulative_length = 0;

		foreach($lines as $line) {
			$length = mb_strlen($line);

			if($cumulative_length + $length > static::MAX_LENGTH) {
				if($cumulative_length > 0) {
					$chunks[] = implode(PHP_EOL, $chunk) . '```';
					$chunk = [];

					$cumulative_length = 3;
					$line = "```{$line}";
				}
			}

			$cumulative_length += $length;
			$chunk[] = $line;
		}

		$chunks[] = implode(PHP_EOL, $chunk);

		$chunks[0] = "{$header}\n{$chunks[0]}";
		$root_message_id = null;

		foreach($chunks as $chunk) {
			if(is_null($root_message_id)) {
				$root_message_id = static::sendRaw($type, $chunk);
			} else {
				static::sendRaw($type, $chunk, $root_message_id);
			}
		}

		return true;
	}

	protected static function sendRaw($type, $text, $thread_id = null) {
		if(!static::$token || !static::$channel) {
			throw new \Exception('Slack Debug is not configured properly. Run `Debug::configure("<TOKEN>", "<CHANNEL>")` first.');
		}

		$channel = static::$channel;

		if(is_array($channel)) {
			$channel = $channel[$type] ?? $channel['log'] ?? pos($channel);
		}

		$params = [
			'token' => static::$token,
			'channel' => $channel,
			'text' => $text,
		];

		if($thread_id) {
			$params['thread_ts'] = $thread_id;
		}

		$ch = curl_init('https://slack.com/api/chat.postMessage');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$response = json_decode(curl_exec($ch), true);

		return $response['ts'] ?? null;
	}
}
