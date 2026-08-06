#!/bin/bash

# AI Dev Kit startup hook
# This hook runs in linked projects to keep kit symlinks up to date

# Try to find kit directory via symlinks
KIT_DIR=""

# Try to find kit through individual skill symlinks
if [ -L "${PWD}/.claude/skills/analyze-jira" ]; then
    KIT_SKILL=$(readlink "${PWD}/.claude/skills/analyze-jira" 2>/dev/null)
    if [ -n "$KIT_SKILL" ]; then
        # If relative symlink, resolve it from .claude directory
        if [[ ! "$KIT_SKILL" = /* ]]; then
            KIT_SKILL="${PWD}/.claude/skills/$KIT_SKILL"
        fi
        # Navigate to kit parent (where .git is, two levels up from skills/skillname)
        KIT_DIR=$(dirname "$(dirname "$KIT_SKILL")")
    fi
fi

if [ -z "$KIT_DIR" ] && [ -L "${PWD}/.claude/hooks/startup.sh" ]; then
    KIT_HOOK=$(readlink "${PWD}/.claude/hooks/startup.sh" 2>/dev/null)
    if [ -n "$KIT_HOOK" ]; then
        # If relative symlink, resolve it from .claude directory
        if [[ ! "$KIT_HOOK" = /* ]]; then
            KIT_HOOK="${PWD}/.claude/hooks/$KIT_HOOK"
        fi
        # Navigate to kit parent (one level up from hooks)
        KIT_DIR=$(dirname "$(dirname "$KIT_HOOK")")
    fi
fi

if [ -n "$KIT_DIR" ] && [ -f "$KIT_DIR/install.sh" ]; then
    cd "$KIT_DIR" || exit 1

    # Get current commit
    CURRENT=$(git rev-parse HEAD 2>/dev/null)
    UPSTREAM=$(git rev-parse @{u} 2>/dev/null)

    if [ -z "$UPSTREAM" ]; then
        # No upstream tracking, try origin/main
        UPSTREAM=$(git rev-parse origin/main 2>/dev/null)
    fi

    if [ "$CURRENT" != "$UPSTREAM" ] && [ -n "$UPSTREAM" ]; then
        echo ""
        echo "⚠️  AI Dev Kit has updates available"
        echo "   Current: ${CURRENT:0:7}"
        echo "   Latest:  ${UPSTREAM:0:7}"
        echo ""

        # Ask user if they want to update
        read -p "   Update now? (y/n) " -n 1 -r
        echo ""

        if [[ $REPLY =~ ^[Yy]$ ]]; then
            echo "   Updating..."
            if git pull > /dev/null 2>&1; then
                echo "   ✅ Kit updated to latest version"
                echo "   Running install script to sync symlinks..."

                # Return to project and run install to update symlinks
                cd - > /dev/null || exit 1
                if bash "$KIT_DIR/install.sh" > /dev/null 2>&1; then
                    echo "   ✅ Symlinks synchronized"
                else
                    echo "   ⚠️  Some symlinks may need manual attention"
                fi
            else
                echo "   ❌ Update failed"
            fi
        fi
        echo ""
    fi
fi
