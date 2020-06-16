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

	public static function values($data, $type = 'log') {
		return static::sendValues($data, $type);
	}

	public static function log(...$data) {
		return static::sendValues($data, 'log');
	}

	public static function error(...$data) {
		return static::sendValues($data, 'error');
	}

	protected static function getSource($offset) {
		// Added +2 because first entry is this function itself and second is the local (since getSource() is protected) caller.
		$offset = $offset + 2;

		// TODO: Improve tracing
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $offset + 1);

		$class = pos(array_reverse(explode('\\', $trace[$offset]['class'])));
		$method = $trace[$offset]['function'];
		$file = $trace[$offset]['file'] ?? '';
		$line = $trace[$offset]['line'] ?? '';

		return "<sftp:/{$file}:{$line}|{$class}@{$method}>";
	}

	public static function formatValue($value) {
		if(is_bool($value)) {
			return $value ? '_[TRUE]_' : '_[FALSE]_';
		}

		if(is_null($value)) {
			return '_[NULL]_';
		}

		if($value instanceof \Throwable) {
			$value = $value->__toString();
		}

		// Split lines if they're too long
		$lines = explode(PHP_EOL, print_r($value, true));
		foreach($lines as &$line) {
			if(mb_strlen($line) > static::MAX_LENGTH) {
				$line = chunk_split($line, static::MAX_LENGTH);
			}
		}

		return '```' . implode(PHP_EOL, $lines) . '```';
	}

	protected static function getValueFormatter($type) {
		return [static::class, 'formatValue'];
	}

	protected static function getValueSeparator($type) {
		return PHP_EOL.PHP_EOL;
	}

	protected static function sendValues(array $values, $type = 'log', callable $formatter = null, $separator = null) {
		$formatter = $formatter ?? static::getValueFormatter($type);
		$separator = $separator ?? static::getValueSeparator($type);

		$values = array_map(function($value, $key) use($formatter) {
			$value = $formatter($value);

			return is_string($key) ? "*{$key}:*\n{$value}" : $value;
		}, $values, array_keys($values));

		return static::send(implode($separator, $values), $type, 2);
	}

	public static function send($message, $type = 'log', $source_offset = 0) {
		$source = static::getSource($source_offset);
		$header = "> *{$source} {$type}*";

		$chunks = [];
		$chunk = [];
		$lines = explode(PHP_EOL, $message);
		$cumulative_length = 0;

		foreach($lines as $i => $line) {
			$length = mb_strlen($line);

			if($cumulative_length + $length > static::MAX_LENGTH) {
				if($cumulative_length > 0) {
					$chunks[] = implode(PHP_EOL, $chunk) . '```';
					$chunk = [];

					if(count($chunks) >= 9) {
						$lines_left = count($lines) - $i;

						if($lines_left > 1) {
							$chunks[] = "_{$lines_left} more lines were skipped._";
							break;
						}
					}

					$cumulative_length = 3;
					$line = "```{$line}";
				}
			}

			$cumulative_length += $length;
			$chunk[] = $line;
		}

		if(count($chunk)) {
			$chunks[] = implode(PHP_EOL, $chunk);
		}

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
