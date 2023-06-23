<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command class for the app:pci-text-analysis command.
 *
 * Allows fetching and analysis of a text file from a valid URL.
 */
class PciTextAnalysis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:pci-text-analysis
        {url : The URL of the file we want to analyze}
        {--f|force : Force any valid text file to be analyzed, regardless of HTTP code or other considerations}
        ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch a text file from a URL and analyze its contents';

    /**
     * Short form of console command description for logging purposes.
     *
     * @var string
     */
    protected $commandName = 'app:pci-text-analysis';

    /**
     * The size of the "Top X" letter array.
     *
     * @var int
     */
    const TOP_LETTER_RESULT_SIZE = 5;

    /**
     * The size of the "Top X" word array.
     *
     * @var int
     */
    const TOP_WORD_RESULT_SIZE = 5;

    /**
     * The number of times to retry the curl request in case of error.
     *
     * @var int
     */
    const CURL_RETRY_ATTEMPTS = 5;

    /**
     * How long (in seconds) to wait before retrying curl execution.
     *
     * @var int
     */
    const CURL_RETRY_DELAY = 3;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $url = $this->argument('url');

        $fileContent = $this->fetchContent($url);

        // Remove all characters that aren't letters or whitespace
        $fileContent = preg_replace('/[^a-zA-Z\s]/', '', $fileContent);

        // Convert all remaining letters to lowercase
        $fileContent = strtolower($fileContent);

        $this->newLine();

        // Count & output the most common letters in the file, capitalized
        $letters = $this->getTopLetters($fileContent);
        $this->info('Top Five Letters:');
        foreach ($letters as $letter => $count) {
            $this->line(sprintf('%s: %d', strtoupper($letter), $count));
        }
        $this->newLine();

        // Count & output the most common words in the file, capitalized
        $words = $this->getMostUsedWords($fileContent);
        $this->info('Most Used Words:');
        foreach ($words as $word => $count) {
            $this->line(sprintf('%s: %d', ucfirst($word), $count));
        }
        $this->newLine();

        // Count & output the number of unique words in the file
        $uniqueWords = $this->countUniqueWords($fileContent);
        $this->info(sprintf('Number of Unique Words: %d', $uniqueWords));
        $this->newLine();

        // Count and output the number of lines in the file containing
        $linesWithWords = $this->countLinesWithWords($fileContent);
        $this->info(sprintf('Number of lines with words: %d', $linesWithWords));

        Log::info(sprintf('%s has completed successfully.', $this->commandName));
        exit(0);
    }

    /**
     * Count and sort the most common letters in a string.
     *
     * @param string $str - The string to count occurrences in
     * @param int $resultCount - The number of letters we want to return; defaults to class constant
     * @return array - An array with letters as keys and counts as values
     */
    private function getTopLetters(string $str, int $resultCount = self::TOP_LETTER_RESULT_SIZE): array
    {
        $letters = preg_replace('/[^a-z]/', '', $str);
        $letterCount = array_count_values(str_split($letters));
        arsort($letterCount);
        return array_slice($letterCount, 0, $resultCount, true);
    }

    /**
     * Count and sort the most common words in a string.
     *
     * @param $str - The string to count occurrences in
     * @param int $resultCount - The number of words we want to return; defaults to class constant
     * @return array - An array with words as keys and counts as values
     */
    private function getMostUsedWords(string $str, int $resultCount = self::TOP_WORD_RESULT_SIZE): array
    {
        $words = str_word_count($str, 1);
        $wordCount = array_count_values($words);
        arsort($wordCount);
        return array_slice($wordCount, 0, $resultCount, true);
    }

    /**
     * Count the number of unique words in a string.
     *
     * @param $str - The string we are counting words from
     * @return int - The unique word count
     */
    private function countUniqueWords(string $str): int
    {
        $words = str_word_count($str, 1);
        $uniqueWords = array_unique($words);
        return count($uniqueWords);
    }

    /**
     * Use curl here instead of file_get_contents for better error info.
     *
     * @param string $url
     * @return bool|string
     */
    private function fetchContent(string $url): bool|string
    {
        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $content = curl_exec($curl);

        $this->executeCurlWithRetry($curl);

        $curlInfo = curl_getinfo($curl);
        $statusCode = $curlInfo['http_code'];

        curl_close($curl);

        // Validate that we're getting a non-empty *text* file.
        if (empty($content) || !$this->isTextFile($content)) {
            $commandError = 'Invalid or empty text file.';
            $this->logErrorAndExit($commandError);
        }

        // Validate that we received an http status code that indicates a successful request
        if ($statusCode >= 400) {
            $statusCodeWarning = sprintf('A valid document was found. However, a %s status code was received.', $statusCode);

            if ($this->option('force')) {
                $this->warn(sprintf('%s Forcing attempted analysis due to -f option.', $statusCodeWarning));
            } else {
                $commandError = sprintf('%s Aborting operation.', $statusCodeWarning);
                $this->logErrorAndExit($commandError);
            }
        }

        return $content;
    }

    /**
     * Check if file is text-only by checking for existence of non-printable characters.
     *
     * @param $fileContent - Contents of the file
     * @return false|int
     */
    private function isTextFile(string $fileContent): bool|int
    {
        // Check if the content contains any non-printable characters
        return preg_match('/[[:print:]]/', $fileContent);
    }

    /**
     * Counts the number of lines in a string which contain words
     *
     * @param $str - Text to check for line (with words) count
     * @return int - Lines (with words) count
     */
    private function countLinesWithWords(string $str): int
    {
        $lines = explode(PHP_EOL, $str);
        $linesWithWordsCount = 0;

        foreach ($lines as $line) {
            if (preg_match('/\w+/', $line)) {
                $linesWithWordsCount++;
            }
        }

        return $linesWithWordsCount;
    }

    /**
     * Executes the curl request
     *
     * @param $curl - The curl handler
     * @param $retryAttempts - Number of retry attempts before erroring out of the command
     * @return bool|string|void
     */
    private function executeCurlWithRetry($curl, $retryAttempts = self::CURL_RETRY_ATTEMPTS)
    {
        $retryCount = 0;

        while ($retryCount < $retryAttempts) {
            $content = curl_exec($curl);

            if ($content !== false) {
                return $content;
            }

            $error = curl_error($curl);
            $retryCount++;

            // Add a bit of a delay before retrying.
            sleep(self::CURL_RETRY_DELAY);

            $this->warn(
                sprintf('Failed to fetch file from URL (Attempt %d): %s', $retryCount, $error)
            );
        }

        $this->logErrorAndExit('Maximum retry attempts reached. Failed to fetch the content.');
    }

    /**
     * Outputs and logs an error to the current logger implementation, and exits the command.
     *
     * @param string $commandError - Error to output and log.
     * @return void
     */
    private function logErrorAndExit(string $commandError): void
    {
        $this->error($commandError);
        Log::error($commandError);
        exit(1);
    }


}
