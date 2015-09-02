DB Cleaning Plugin for n98 MageRun
===

** Disclaimer:  This is super dangerous and should probably never be used.  So... shoo **

## Installation

[See wiki](https://github.com/netz98/n98-magerun/wiki/Modules#where-can-modules-be-placed)

## Usage

```
n98-magerun.phar db:maintain:clean-tables [-f|--force]
```

This will start the table `TRUNCATE`ing.  If you *didn't* use `-f` it will show which tables are about to be nuked and ask you to confirm before continuing.

---

This module is based on [this Stack Overflow answer](http://stackoverflow.com/a/28057465/763468).