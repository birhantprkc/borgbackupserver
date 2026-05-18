(function(window) {
    const BBS = window.BBS = window.BBS || {};
    const durationCache = new Map();

    BBS.formatDuration = function(seconds) {
        seconds = Math.max(0, parseInt(seconds, 10) || 0);
        if (seconds <= 0) return '--';
        if (durationCache.has(seconds)) return durationCache.get(seconds);

        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;

        let label;
        if (days > 0) {
            label = days + 'd ' + hours + 'h';
        } else if (hours > 0) {
            label = hours + 'h ' + minutes + 'm';
        } else if (minutes > 0) {
            label = minutes + 'm ' + secs + 's';
        } else {
            label = secs + 's';
        }

        durationCache.set(seconds, label);
        return label;
    };
})(window);
