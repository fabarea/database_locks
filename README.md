Database locks
==============

The default TYPO3 locking strategy is "FileLockStrategy." This approach is not ideal when the source code is shared
between multiple servers (e.g., via a shared file system on NFS). While Redis is an option to solve this issue,
it is not always available. We can use the in-memory storage of the database to store the locks.

This extension addresses exactly that - it provides a **database lock** strategy for TYPO3.
