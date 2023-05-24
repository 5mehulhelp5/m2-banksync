# M2-BankSync

This Magento 2 module is used to import bank statements and match them
with invoices and credit memos.

### Installation

```bash
composer require --no-update m2-banksync
composer update m2-banksync
bin/magento module:enable Ibertrand_BankSync
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```
 
### Configuration

Go to `Stores > Configuration > Sales > Bank Sync` to configure the module.
Available options are:

| Option                                                 | Description                                               |
|--------------------------------------------------------|-----------------------------------------------------------|
| General > Enabled                                      | Enable or disable the module                              |
| General > Async Matching                               | Enable or disable async matching via cron job (suggested) |
| Matching > Document Selection > Amount Threshold       | Maximum amount difference                                 |
| Matching > Document Selection > Date Threshold         | Max days for the invoice to be created after the payment  |
| Matching > Document Selection > Payment Methods        | Payment methods to be considered                          |
| Matching > Document Selection > Start Date             | Consider only documents created after this date           |
| Matching > Weights > ...                               | Weight for the name, amount and purpose text matching     |
| Matching > Weights > Patterns > ...                    | Regex patterns to use for purpose matching                |
| Matching > Weights > Match confidence thresholds > ... | Thresholds for match evaluation                           |
| CSV Settings > ...                                     | Definition of the import CSV format                       |


### Usage 

Go to `Sales > Bank Sync > New Transactions` to import bank statements and compare them with the existing invoices and credit memos.
Once you have confirmed the matches (booked the transactions), you'll find them in `Sales > Bank Sync > Booked Transactions`.