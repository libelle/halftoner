#!/bin/bash
declare -a shape=("c" "h" "d" "s" "a" "l" "w")
declare -a thresh=(0 10 20 30)
declare -a dots=(80 100 120)
declare -a coverage=(80 90 100)
declare -a opts=("" "-i" "-e")

for i in "${shape[@]}"
do
   for j in "${thresh[@]}"
   do
       for k in "${dots[@]}"
       do
            for l in "${coverage[@]}"
            do
                for m in "${opts[@]}"
                do
                    #echo "php halftoner.php -s Cat.jpg -S $i -l $j -d $k -p $l $m"
                    `php halftoner.php -s Cat.jpg -S $i -l $j -d $k -p $l $m`
                    `php halftoner.php -s Dog.jpg -S $i -l $j -d $k -p $l $m`
                done
            done
       done
   done
done
