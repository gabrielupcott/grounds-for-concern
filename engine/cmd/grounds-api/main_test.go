package main

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func post(handler http.HandlerFunc, body string) *httptest.ResponseRecorder {
	req := httptest.NewRequest(http.MethodPost, "/", strings.NewReader(body))
	rec := httptest.NewRecorder()
	handler(rec, req)
	return rec
}

const validEvaluate = `{
  "rule": {
    "name": "Coffee budget",
    "window_days": 7,
    "group": {"match": "all", "conditions": [{"field": "category", "operator": "is", "value": "coffee"}]},
    "threshold": {"metric": "total", "operator": ">", "value": 60}
  },
  "transactions": [
    {"occurred_on": "2026-09-01", "merchant": "Tim Hortons", "category": "coffee", "amount_cents": 3000},
    {"occurred_on": "2026-09-02", "merchant": "Starbucks", "category": "coffee", "amount_cents": 3101},
    {"occurred_on": "2026-09-02", "merchant": "Fortinos", "category": "groceries", "amount_cents": 9000}
  ],
  "as_on": "2026-09-03"
}`

func TestEvaluateHandler(t *testing.T) {
	rec := post(handleEvaluate, validEvaluate)
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, body = %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), `"triggered":true`) {
		t.Errorf("expected triggered:true, got %s", rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), `"window_total_cents":6101`) {
		t.Errorf("expected window_total_cents 6101, got %s", rec.Body.String())
	}
}

func TestEvaluateRejectsUnknownFields(t *testing.T) {
	rec := post(handleEvaluate, strings.Replace(validEvaluate, `"as_on"`, `"asOf"`, 1))
	if rec.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want 400 for unknown field", rec.Code)
	}
}

func TestEvaluateRejectsInvalidRule(t *testing.T) {
	rec := post(handleEvaluate, `{"rule": {"name": ""}, "transactions": []}`)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want 400 for invalid rule", rec.Code)
	}
}

func TestEvaluateMethodNotAllowed(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/", bytes.NewReader(nil))
	rec := httptest.NewRecorder()
	handleEvaluate(rec, req)
	if rec.Code != http.StatusMethodNotAllowed {
		t.Errorf("status = %d, want 405", rec.Code)
	}
}

func TestBacktestHandler(t *testing.T) {
	body := `{
      "rule": {
        "name": "Coffee budget", "window_days": 7,
        "group": {"match": "all", "conditions": [{"field": "category", "operator": "is", "value": "coffee"}]},
        "threshold": {"metric": "total", "operator": ">", "value": 60}
      },
      "transactions": [
        {"occurred_on": "2026-08-01", "merchant": "Starbucks", "category": "coffee", "amount_cents": 6500},
        {"occurred_on": "2026-08-21", "merchant": "Starbucks", "category": "coffee", "amount_cents": 7000}
      ],
      "from": "2026-07-25", "to": "2026-09-15"
    }`
	rec := post(handleBacktest, body)
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, body = %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), `"episode_count":2`) {
		t.Errorf("expected episode_count 2, got %s", rec.Body.String())
	}
}

func TestBacktestRejectsBadDates(t *testing.T) {
	body := `{"rule": {"name":"x","window_days":7,"group":{"match":"all","conditions":[{"field":"category","operator":"is","value":"coffee"}]},"threshold":{"metric":"total","operator":">","value":60}}, "transactions": [], "from": " Aug 1", "to": "2026-09-01"}`
	rec := post(handleBacktest, body)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want 400 for bad date", rec.Code)
	}
}
