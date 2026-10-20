package storage

// SetBeforePut makes fn run between looking at an object and writing it, so a test can let another writer in there.
func (s *S3) SetBeforePut(fn func(key string)) {
	s.beforePut = fn
}
