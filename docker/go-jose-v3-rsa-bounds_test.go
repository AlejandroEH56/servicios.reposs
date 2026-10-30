package jose

import (
	"crypto/rand"
	"crypto/rsa"
	"encoding/base64"
	"encoding/json"
	"math/big"
	"strings"
	"testing"
)

func TestResidenciesRSAParameterBounds(t *testing.T) {
	encode := func(bits uint) string {
		n := new(big.Int).Lsh(big.NewInt(1), bits-1)
		return base64.RawURLEncoding.EncodeToString(n.SetBit(n, 0, 1).Bytes())
	}
	for _, test := range []struct {
		name string
		key  map[string]string
		want string
	}{
		{"large public modulus", map[string]string{"kty": "RSA", "n": encode(4097), "e": "AQAB"}, "modulus too large"},
		{"large private modulus", map[string]string{"kty": "RSA", "n": encode(4097), "e": "AQAB", "d": "Aw", "p": "Aw", "q": "Aw"}, "modulus too large"},
		{"oversized prime before validation", map[string]string{"kty": "RSA", "n": encode(2048), "e": "AQAB", "d": "Aw", "p": encode(16384), "q": "Aw"}, "p * q != n"},
		{"mismatched bounded primes", map[string]string{"kty": "RSA", "n": encode(2048), "e": "AQAB", "d": "Aw", "p": "Aw", "q": "Aw"}, "p * q != n"},
	} {
		t.Run(test.name, func(t *testing.T) {
			data, err := json.Marshal(test.key)
			if err != nil {
				t.Fatal(err)
			}
			var key JSONWebKey
			if err = json.Unmarshal(data, &key); err == nil || !strings.Contains(err.Error(), test.want) {
				t.Fatalf("expected %s, got %v", test.want, err)
			}
		})
	}
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	for _, material := range []interface{}{key, &key.PublicKey} {
		data, err := json.Marshal(JSONWebKey{Key: material})
		if err != nil {
			t.Fatal(err)
		}
		var parsed JSONWebKey
		if err = json.Unmarshal(data, &parsed); err != nil {
			t.Fatalf("valid RSA roundtrip: %v", err)
		}
	}
}
