| kind | pass | S0-base: WAL B/write (flush B/write; ms/write) | S1-K1: WAL B/write (flush B/write; ms/write) | S2-K1-F2: WAL B/write (flush B/write; ms/write) | S3-K1-F2-Z1: WAL B/write (flush B/write; ms/write) |
|---|---|---|---|---|---|
| product | cold | 22576 (22510–22642); flush 15223; 0.22 ms | 35831 (35448–36215); flush 17093; 2.84 ms | 36654 (36572–36736); flush 30541; 5.68 ms | 37030 (36395–37665); flush 32965; 2.70 ms |
| product | hot | 5818 (5803–5834); flush 166; 0.18 ms | 5550 (5250–5849); flush 148; 2.69 ms | 5858 (4960–6755); flush 1687; 5.02 ms | 6084 (5381–6787); flush 722; 1.27 ms |
| order-cycle | cold | 35389 (35185–35594); flush 23809; 1.06 ms | 38282 (36919–39644); flush 22815; 17.36 ms | 36944 (36467–37420); flush 23282; 10.56 ms | 41960 (41696–42224); flush 42237; 7.07 ms |
| order-cycle | hot | 26841 (26675–27006); flush 0; 0.96 ms | 26562 (26437–26687); flush 0; 10.67 ms | 26782 (26587–26978); flush 0; 15.91 ms | 24047 (17447–30646); flush 0; 4.32 ms |
| customer | cold | 14044 (13761–14328); flush 6432; 0.20 ms | 14926 (14793–15059); flush 6349; 1.93 ms | 14907 (14849–14965); flush 7698; 2.18 ms | 16785 (16718–16851); flush 13045; 0.93 ms |
| customer | hot | 7526 (7483–7568); flush 1920; 0.18 ms | 7629 (7624–7635); flush 488; 2.16 ms | 6883 (6070–7696); flush 0; 3.64 ms | 8861 (8830–8892); flush 1162; 0.85 ms |
