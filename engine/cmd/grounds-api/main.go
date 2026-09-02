// grounds-api is the HTTP face of the rule engine: stateless, JSON in, JSON
// out. PHP owns the database and the clock; this service only does math.
package main

import (
	"encoding/json"
	"errors"
	"log"
	"net/http"
	"time"

	"github.com/gabrielupcott/grounds/internal/engine"
)

type evaluateRequest struct {
	Rule         engine.Rule           `json:"rule"`
	Transactions []engine.Transaction  `json:"transactions"`
	AsOn         string                `json:"as_on,omitempty"`
}

type backtestRequest struct {
	Rule         engine.Rule           `json:"rule"`
	Transactions []engine.Transaction  `json:"transactions"`
	From         string                `json:"from"`
	To           string                `json:"to"`
}

// decodeJSON is strict: unknown fields are rejected so a typo on one side of
// the PHP/Go boundary fails loudly instead of silently producing a wrong
// verdict.
func decodeJSON[T any](r *http.Request) (T, error) {
	var v T
	dec := json.NewDecoder(http.MaxBytesReader(nil, r.Body, 4<<20))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&v); err != nil {
		return v, err
	}
	return v, nil
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, status int, err error) {
	writeJSON(w, status, map[string]string{"error": err.Error()})
}

func handleEvaluate(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeError(w, http.StatusMethodNotAllowed, errors.New("POST required"))
		return
	}
	req, err := decodeJSON[evaluateRequest](r)
	if err != nil {
		writeError(w, http.StatusBadRequest, err)
		return
	}

	asOn := time.Now().UTC().Truncate(24 * time.Hour)
	if req.AsOn != "" {
		asOn, err = engine.ParseDate(req.AsOn)
		if err != nil {
			writeError(w, http.StatusBadRequest, err)
			return
		}
	}

	verdict, err := engine.Evaluate(req.Rule, req.Transactions, asOn)
	if err != nil {
		writeError(w, http.StatusBadRequest, err)
		return
	}
	writeJSON(w, http.StatusOK, verdict)
}

func handleBacktest(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeError(w, http.StatusMethodNotAllowed, errors.New("POST required"))
		return
	}
	req, err := decodeJSON[backtestRequest](r)
	if err != nil {
		writeError(w, http.StatusBadRequest, err)
		return
	}

	from, err := engine.ParseDate(req.From)
	if err != nil {
		writeError(w, http.StatusBadRequest, err)
		return
	}
	to, err := engine.ParseDate(req.To)
	if err != nil {
		writeError(w, http.StatusBadRequest, err)
		return
	}

	result, err := engine.Backtest(req.Rule, req.Transactions, from, to)
	if err != nil {
		writeError(w, http.StatusBadRequest, err)
		return
	}
	writeJSON(w, http.StatusOK, result)
}

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	mux.HandleFunc("/v1/evaluate", handleEvaluate)
	mux.HandleFunc("/v1/backtest", handleBacktest)

	addr := ":8081"
	log.Printf("grounds-api listening on %s", addr)
	log.Fatal(http.ListenAndServe(addr, mux))
}
