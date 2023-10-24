# M2-BankSync

M2-BankSync is a Magento 2 module designed to import bank statements and match them with invoices, credit memos.
It can automatically send payment reminders and dunnings.

## Installation

```bash
composer require --no-update ibertrand/banksync
composer update ibertrand/banksync
bin/magento module:enable Ibertrand_BankSync
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```

## Configuration

Go to `Stores > Configuration > Sales > Bank Sync` to configure the module.
Available options are:

| Option Group   | Option                                                   | Description                                                 |
|----------------|----------------------------------------------------------|-------------------------------------------------------------|
| General        | Enabled                                                  | Enable or disable the module                                |
|                | Async Matching                                           | Enable or disable async matching via cron job (suggested)   |
|                | Support Creditmemos                                      | Enable to match credit memos as well as invoices            |
| Matching       | Document Selection > Amount Threshold                    | Maximum amount difference for matching                      |
|                | Document Selection > Date Threshold                      | Max days between payment and invoice creation               |
|                | Document Selection > Payment Methods                     | Payment methods to be considered                            |
|                | Document Selection > Start Date                          | Consider only documents created after this date             |
|                | Document Selection > Document Nr Pattern                 | Regex to extract document number from purpose               |
|                | Document Selection > Order Nr Pattern                    | Regex to extract order number from purpose                  |
|                | Document Selection > Customer Nr Pattern                 | Regex to extract customer number from purpose               |
|                | Weights > Payer Name                                     | Weight for name matching                                    |
|                | Weights > Purpose                                        | Weight for purpose text matching                            |
|                | Weights > Amount                                         | Weight for amount matching                                  |
|                | Weights > Strict Amount Matching                         | Apply weight for exact matches or linearly                  |
|                | Patterns for purpose matching > Document Increment ID    | Regex pattern for document ID matching                      |
|                | Patterns for purpose matching > Order Increment ID       | Regex pattern for order ID matching                         |
|                | Patterns for purpose matching > Customer Increment ID    | Regex pattern for customer ID matching                      |
|                | Match confidence thresholds > Acceptance Threshold       | Threshold for automatic booking                             |
|                | Match confidence thresholds > Minimum Threshold          | Minimum threshold for match listing                         |
| Dunnings       | Enabled                                                  | Enable or disable dunnings                                  |
|                | Automatically send dunnings via mail                     | Enable automatic dunning mail dispatch                      |
|                | Email Sender                                             | Select the email identity for dunning mails                 |
|                | Payment due date                                         | Specify the due date for payments in days after invoice     |
|                | Types > Reminder 1, 2, Dunning 1, 2, 3                   | Define individual dunning/reminder types with settings      |
| -------------- | -------------------------------------------------------- | ----------------------------------------------------------- |

## CSV format settings

Different CSV formats can be defiend in the import
form: `Sales > Bank Sync > New Transactions > Import new transactions > CSV formats`

## Usage

Go to `Sales > Bank Sync > New Transactions` to import bank statements and compare them with the existing invoices and credit memos.
Once you have confirmed the matches (booked the transactions), you'll find them in `Sales > Bank Sync > Booked Transactions`.
