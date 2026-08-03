export default {
    extends: ['@commitlint/config-conventional'],
    rules: {
        // This repo's history uses long, descriptive headers (e.g. release
        // one-liners with a trailing version) and capitalized product terms
        // in subjects, so these two defaults are disabled on purpose.
        'header-max-length': [0],
        'subject-case': [0],
        'body-max-line-length': [0],
    },
};
