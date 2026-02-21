/*
 * bbs-mac-agent — macOS FDA wrapper for the BBS backup agent.
 *
 * This compiled binary launches the Python agent script. Users grant
 * Full Disk Access to this binary instead of hunting for python3
 * symlinks. The FDA permission is inherited by child processes (python3,
 * borg) so backups can access protected directories.
 *
 * Build universal binary:
 *   cc -arch arm64 -arch x86_64 -o bbs-mac-agent bbs-mac-agent.c
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#define AGENT_SCRIPT "/opt/bbs-agent/bbs-agent.py"

int main(void) {
    /* Find python3 — check common locations */
    const char *python_paths[] = {
        "/opt/homebrew/bin/python3",
        "/usr/local/bin/python3",
        "/usr/bin/python3",
        NULL
    };

    const char *python = NULL;
    for (int i = 0; python_paths[i]; i++) {
        if (access(python_paths[i], X_OK) == 0) {
            python = python_paths[i];
            break;
        }
    }

    if (!python) {
        fprintf(stderr, "bbs-mac-agent: python3 not found\n");
        return 1;
    }

    /* Set PATH so borg is findable */
    setenv("PATH", "/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin", 1);

    char *args[] = {(char *)python, AGENT_SCRIPT, NULL};
    execv(python, args);

    /* execv only returns on error */
    perror("bbs-mac-agent: execv failed");
    return 1;
}
