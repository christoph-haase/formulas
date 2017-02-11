#! /bin/bash

mkdir models
# Download the archive:
curl http://mcc.lip6.fr/archives/MCC-INPUTS.tgz \
     -o models/MCC-INPUTS.tgz
cd models
# Extract it:
tar zxf MCC-INPUTS.tgz
rm MCC-INPUTS.tgz
cd BenchKit/INPUTS/
# Remove all colored models:
rm *-COL-*.tgz
# Expand all remaining models:
for file in *.tgz
do
  echo ${file}
  tar zxf ${file}
  rm ${file}
  # Remove useless formula files:
  rm $(find . -name "CTL*")
  rm $(find . -name "LTL*")
  rm $(find . -name "Reachability*")
done
cd ../../..

# Convert all models to spec:
php mcc.php model:to-spec \
    models/BenchKit/INPUTS/

# Generate formulas for all models:
php mcc.php formula:generate \
    --output=ReachabilitySpec \
    --chain \
    --quantity=10 \
    --subcategory=ReachabilitySpec \
    --depth=5  \
    --no-filtering \
    models/BenchKit/INPUTS/

