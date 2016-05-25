<?php

/**
 * @license    New BSD License
 * @link       https://github.com/nextras/tracy-monolog-adapter
 */

namespace Nextras\TracyMonologAdapter\Processors;

use Throwable;
use Tracy\BlueScreen;
use Tracy\Helpers;


class TracyExceptionProcessor
{
	/** @var string */
	private $directory;

	/** @var BlueScreen */
	private $blueScreen;


	public function __construct(string $logDirectory, BlueScreen $blueScreen)
	{
		$this->directory = $logDirectory;
		$this->blueScreen = $blueScreen;
	}


	public function __invoke(array $record)
	{
		if (isset($record['context']['exception'])) {
			list($justCreated, $exceptionFileName) = $this->logException($record['context']['exception']);
			$record['context']['tracy_filename'] = basename($exceptionFileName);
			$record['context']['tracy_created'] = $justCreated;
			if ($record['message'] === '') {
				$record['message'] = self::formatMessage($record['context']['exception']);
			}
		}
		return $record;
	}


	/**
	 * @author David Grudl
	 * @see    https://github.com/nette/tracy
	 */
	protected function logException(Throwable $exception): array
	{
		$file = $this->getExceptionFile($exception);
		if ($handle = @fopen($file, 'x')) { // @ file may already exist
			ob_start(); // double buffer prevents sending HTTP headers in some PHP
			ob_start(function ($buffer) use ($handle) { fwrite($handle, $buffer); }, 4096);
			$this->blueScreen->render($exception);
			ob_end_flush();
			ob_end_clean();
			fclose($handle);
			return [true, $file];
		} else {
			return [false, $file];
		}
	}


	/**
	 * @author David Grudl
	 * @see    https://github.com/nette/tracy
	 */
	private function getExceptionFile(Throwable $exception): string
	{
		$dir = strtr($this->directory . '/', '\\/', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
		$hash = substr(md5(preg_replace('~(Resource id #)\d+~', '$1', $exception)), 0, 10);
		foreach (new \DirectoryIterator($this->directory) as $file) {
			if (strpos($file, $hash)) {
				return $dir . $file;
			}
		}
		return $dir . 'exception--' . @date('Y-m-d--H-i') . "--$hash.html"; // @ timezone may not be set
	}


	/**
	 * @author David Grudl
	 * @see    https://github.com/nette/tracy
	 */
	protected static function formatMessage(Throwable $message): string
	{
		$tmp = [];
		while ($message) {
			$tmp[] = ($message instanceof \ErrorException
				? Helpers::errorTypeToString($message->getSeverity()) . ': ' . $message->getMessage()
				: Helpers::getClass($message) . ': ' . $message->getMessage()
			) . ' in ' . $message->getFile() . ':' . $message->getLine();
			$message = $message->getPrevious();
		}
		$message = implode($tmp, "\ncaused by ");
		return trim($message);
	}
}
