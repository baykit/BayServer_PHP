# BayServer for PHP

# 3.0.0

- [Core] Performes a significant overall refactoring.
- [Core] Introduces a multiplexer type to allow flexible configuration of the I/O multiplexing method.
- [Core] Adopts the CIDR format for specifying source IP control.
- [CGI] Introduce the maxProcesses parameter to allow control over the number of processes to be started.

# 2.3.3

- [core] Fixes the issue encountered when aborting GrandAgent.
- [core] Fixes some small bugs.

# 2.3.2

- [core] Addresses potential issues arising from I/O errors.
- [core] Fixes the issue encountered when aborting GrandAgent.

# 2.3.1

- [core][http] Fixes some bugs

# 2.3.0

- [CGI] Supports "timeout" parameter. (The timed-out CGI processes are killed)
- [Core] Improves the memusage output
- [Core] Fixes some bugs

# 2.2.1

- Fixes some bugs

# 2.2.0

- Supports composer

# 2.1.2

- Fixes bug when CGI docker could not spawn process

# 2.1.1

- Fixes some bugs on error handling

# 2.1.0

- Supports multi core mode on Windows
- Fixes some bugs

# 2.0.6

- Fixes bug when received 404 NotFound POST message
- Fixed problems on handling admin-ajax.php of WordPress (client's unexpectedly abort)
- Fixes problem on handling wp-cron.php of WordPress
- Fixes several bugs

# 2.0.5

- Fixes problem of H2 docker

# 2.0.4

- Supports memusage command
- Fixes some bugs

# 2.0.3

- Modifies bayserver.plan to avoid resolving host name


# 2.0.2

- Fixes problem of FCGI docker


# 2.0.1

- Fixes an HTTP/2 packet parser bug.


# 2.0.0

- First version
