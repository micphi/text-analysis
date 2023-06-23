## Welcome

This project's purpose is to create a command which, when run, will fetch a text file from a URL and output various statistics related to the file's contents.

The command class can be found at `app/Console/Commands/PciTextAnalysis.php`


## Installation

This project uses Laravel's Sail utility for ease of development regardless of platform. It can be installed using the following steps.

1. Install Docker Desktop if not already installed.
2. Clone the repository.
3. Run the following commands from the project's root directory:
    1. `./vendor/bin/sail up`
   2. `./vendor/bin/sail composer install`

## Running

Running the analysis script is as simple as `./vendor/bin/sail artisan app:pci-text-analysis {url}`

You can pass the `-f` or `--force` option if you would like to ignore HTTP status code errors and continue processing any information retrieved from the URL.

This could be useful in circumstances where you wish to parse an error message for some purpose.
