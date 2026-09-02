package engine

import (
	"fmt"
	"sort"
	"time"
)

// Episode is one contiguous run of firing days. Consecutive days collapse
// into a single episode, because "it fired 3 times" should mean three bad
// weeks, not the same bad week counted five days in a row.
type Episode struct {
	Start          string `json:"start"`
	End            string `json:"end"`
	PeakDay        string `json:"peak_day"`
	PeakTotalCents int64  `json:"peak_total_cents"`
	PeakCount      int    `json:"peak_count"`
}

// BacktestResult reports which episodes a rule would have produced.
type BacktestResult struct {
	From          string    `json:"from"`
	To            string    `json:"to"`
	WindowDays    int       `json:"window_days"`
	EpisodeCount  int       `json:"episode_count"`
	Episodes      []Episode `json:"episodes"`
}

// Backtest replays a rule across [from..to], one window per day.
//
// Matching transactions are filtered and bucketed by day once (the rule
// doesn't change with time), then a two-pointer window slides across the
// calendar: each day's bucket enters once and leaves once, so the whole
// backtest is O(n log n + d) instead of re-scanning all transactions per day.
func Backtest(rule Rule, txns []Transaction, from, to time.Time) (BacktestResult, error) {
	if err := rule.Validate(); err != nil {
		return BacktestResult{}, err
	}
	if from.After(to) {
		return BacktestResult{}, fmt.Errorf("from %s is after to %s", from, to)
	}

	type bucket struct {
		day   time.Time
		sum   int64
		count int
	}
	byDay := map[string]*bucket{}
	for _, t := range txns {
		if !MatchesGroup(t, rule.Group) {
			continue
		}
		d, err := ParseDate(t.OccurredOn)
		if err != nil {
			return BacktestResult{}, err
		}
		b := byDay[t.OccurredOn]
		if b == nil {
			b = &bucket{day: d}
			byDay[t.OccurredOn] = b
		}
		b.sum += t.AmountCents
		b.count++
	}

	days := make([]*bucket, 0, len(byDay))
	for _, b := range byDay {
		days = append(days, b)
	}
	sort.Slice(days, func(i, j int) bool { return days[i].day.Before(days[j].day) })

	result := BacktestResult{
		From:       from.Format(dateLayout),
		To:         to.Format(dateLayout),
		WindowDays: rule.WindowDays,
		Episodes:   []Episode{},
	}

	var (
		sum   int64
		count int
		hi, lo int
		curIdx = -1 // index into result.Episodes while an episode is open
	)

	for d := from; !d.After(to); d = d.AddDate(0, 0, 1) {
		cutoff := d.AddDate(0, 0, -(rule.WindowDays - 1))

		for hi < len(days) && !days[hi].day.After(d) {
			sum += days[hi].sum
			count += days[hi].count
			hi++
		}
		for lo < hi && days[lo].day.Before(cutoff) {
			sum -= days[lo].sum
			count -= days[lo].count
			lo++
		}

		if MeetsThreshold(rule.Threshold, sum, count) {
			day := d.Format(dateLayout)
			if curIdx == -1 {
				result.Episodes = append(result.Episodes, Episode{
					Start:          day,
					End:            day,
					PeakDay:        day,
					PeakTotalCents: sum,
					PeakCount:      count,
				})
				curIdx = len(result.Episodes) - 1
			} else {
				e := &result.Episodes[curIdx]
				e.End = day
				if sum > e.PeakTotalCents {
					e.PeakTotalCents = sum
					e.PeakDay = day
					e.PeakCount = count
				}
			}
		} else {
			curIdx = -1
		}
	}

	result.EpisodeCount = len(result.Episodes)
	return result, nil
}
