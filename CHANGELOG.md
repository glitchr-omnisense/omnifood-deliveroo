# Changelog

## [Unreleased]

### Fixed

- A `ProviderException` no longer carries the client secret and the token. The message quoted the
  HTTP client's (the whole URL), the previous was the client's own exception, and every trace of the
  chain kept the arguments of the calls under it (the request's options, the headers and body with
  the credentials) when `zend.exception_ignore_args` is off: a log of the whole chain wrote them.
  The message masks them as `***`, the previous is a copy of the client's exception (the same class
  and code), and no exception `Api` throws keeps its trace's arguments (`Omnifood\Http\Redaction`).
- A 2xx answer whose body is not JSON (a proxy's or a maintenance page) is a `ProviderException`
  (`An answer that is not JSON (HTTP 200).`), where it was read as an empty answer.
