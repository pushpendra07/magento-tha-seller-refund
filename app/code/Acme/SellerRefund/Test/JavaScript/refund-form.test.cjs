const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {runInNewContext} = require('node:vm');

function widget() {
    let prototype;
    const requests = [];
    const values = {};
    const element = {
        find() { return this; },
        text() { return this; },
        prop() { return this; },
        attr(name, value) { values[name] = value; return this; },
        val() { return 'defect'; }
    };
    const jquery = {
        acme: {},
        widget(name, object) { prototype = object; this.acme.acmeRefundForm = object; },
        ajax(request) {
            requests.push(request.data);
            const promise = {done() { return this; }, fail() { return this; }};
            return promise;
        }
    };
    const source = process.env.REFUND_FORM_SOURCE || resolve(__dirname, '../../view/adminhtml/web/js/refund-form.js');
    runInNewContext(readFileSync(source, 'utf8'), {
        define(dependencies, factory) { factory(jquery, value => value, {}, () => {}, () => {}); }
    });
    const instance = Object.assign({}, prototype, {
        element,
        options: Object.assign({}, prototype.options, {submissionToken: 'a'.repeat(32)}),
        _collectItems() { return {1: '1'}; },
        _setState(message, level) { this.message = message; this.level = level; }
    });
    return {instance, requests, values};
}

for (const status of ['business_rejected', 'conflict', 'invalid_payload', 'retries_exhausted']) {
    test(`${status} never promises an automatic retry and keeps the details link`, () => {
        const {instance, values} = widget();
        instance._renderServerState({create_status: status, redirect_url: '/refund/7'});
        assert.doesNotMatch(instance.message, /pending retry|retry is scheduled/i);
        assert.match(instance.message, /rejected|conflict|invalid|limit/);
        assert.equal(values.href, '/refund/7');
        assert.equal(instance.level, 'warning');
    });
}

test('temporary failures explain that a retry is scheduled', () => {
    const {instance} = widget();
    instance._renderServerState({create_status: 'retryable_error'});
    assert.match(instance.message, /retry is scheduled/);
});

test('failed main state never reports success or automatic recovery', () => {
    const {instance} = widget();
    instance._renderServerState({status: 'failed', create_status: 'pending'});
    assert.match(instance.message, /failed/);
    assert.doesNotMatch(instance.message, /being processed|retry is scheduled/);
});

test('successful ERP acceptance is displayed as success', () => {
    const {instance} = widget();
    instance._renderServerState({create_status: 'succeeded'});
    assert.equal(instance.level, 'success');
});

test('retrying Save sends the same per-form submission token', () => {
    const {instance, requests} = widget();
    instance._save();
    instance.inFlight = false;
    instance._save();
    assert.equal(requests.length, 2);
    assert.equal(requests[0].submission_token, 'a'.repeat(32));
    assert.equal(requests[1].submission_token, requests[0].submission_token);
});
